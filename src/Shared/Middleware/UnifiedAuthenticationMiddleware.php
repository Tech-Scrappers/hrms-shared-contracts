<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Shared\Services\ApiKeyService;
use Shared\Services\HybridDatabaseService;
use Shared\Services\SecurityService;
use Shared\Services\DatabaseOptimizationService;

class UnifiedAuthenticationMiddleware
{
    protected $apiKeyService;

    protected $hybridDatabaseService;

    protected $securityService;

    protected $databaseOptimizationService;

    public function __construct(
        ApiKeyService $apiKeyService, 
        HybridDatabaseService $hybridDatabaseService,
        SecurityService $securityService,
        DatabaseOptimizationService $databaseOptimizationService
    ) {
        $this->apiKeyService = $apiKeyService;
        $this->hybridDatabaseService = $hybridDatabaseService;
        $this->securityService = $securityService;
        $this->databaseOptimizationService = $databaseOptimizationService;
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Try API key authentication first (only if both secret and ID are present)
            $apiKey = $request->header('HRMS-Client-Secret');
            $clientId = $request->header('HRMS-Client-ID');
            if ($apiKey && $clientId) {
                return $this->handleApiKeyAuthentication($request, $next, $apiKey);
            }

            // Try OAuth2 authentication
            $authHeader = $request->header('Authorization');
            if ($authHeader && Str::startsWith($authHeader, 'Bearer ')) {
                return $this->handleOAuth2Authentication($request, $next, $authHeader);
            }

            return $this->unauthorizedResponse('Authentication required. Provide either HRMS-Client-Secret header or Authorization Bearer token.');
        } catch (\Exception $e) {
            Log::error('UnifiedAuthenticationMiddleware error: '.$e->getMessage(), ['exception' => $e]);

            return $this->serverErrorResponse('Authentication failed due to an internal server error.');
        }
    }

    /**
     * Handle API key authentication
     */
    protected function handleApiKeyAuthentication(Request $request, Closure $next, string $apiKey): mixed
    {
        try {
            // Validate API key format
            if (! $this->isValidApiKeyFormat($apiKey)) {
                return $this->unauthorizedResponse('Invalid API key format');
            }

            // Get API key details
            $apiKeyData = $this->apiKeyService->validateApiKey($apiKey);
            if (! $apiKeyData) {
                return $this->unauthorizedResponse('Invalid API key');
            }

            // Check if API key is active
            if (! $apiKeyData['is_active']) {
                return $this->unauthorizedResponse('API key is inactive');
            }

            // CRITICAL FIX: Validate tenant context for API key
            $tenantValidation = $this->validateApiKeyTenantContext($request, $apiKeyData);
            if (! $tenantValidation['valid']) {
                // Log security event for cross-tenant API key access attempt
                $this->securityService->logApiKeyEvent('api_key_cross_tenant_access_attempt', $apiKeyData, $request, [
                    'requested_tenant_domain' => $request->header('X-Tenant-Domain', 'not_provided'),
                    'reason' => $tenantValidation['message'],
                ]);

                return $this->unauthorizedResponse($tenantValidation['message']);
            }

            // Set tenant context
            $tenantId = $apiKeyData['tenant_id'];

            // Get tenant information from database (with caching)
            $tenant = $this->databaseOptimizationService->getCachedTenant($tenantId);

            $request->merge([
                'tenant_id' => $tenantId,
                'tenant_domain' => $tenant->domain,
                'tenant_name' => $tenant->name,
            ]);

            $this->hybridDatabaseService->switchToTenantDatabase($tenantId);

            // Set authenticated API key on request
            $request->merge(['api_key' => $apiKeyData]);
            
            // Expose API key permissions for downstream scope middleware
            $request->merge(['api_key_permissions' => $apiKeyData['permissions'] ?? []]);

            // Set auth_user in request for downstream middleware
            $authUser = [
                'id' => $apiKeyData['id'],
                'name' => $apiKeyData['name'],
                'email' => $apiKeyData['email'] ?? null,
                'role' => $apiKeyData['role'] ?? 'api_key',
                'tenant_id' => $apiKeyData['tenant_id'],
                'is_active' => $apiKeyData['is_active'],
            ];
            $request->merge(['auth_user' => $authUser]);

            // Set the authenticated user in Laravel's auth system
            $this->setAuthenticatedUser($request, $authUser);

            // Log successful API key authentication
            $this->logApiKeyAuthenticationSuccess($request, $apiKeyData);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('API key authentication error: '.$e->getMessage(), ['exception' => $e]);

            return $this->unauthorizedResponse('API key authentication failed');
        }
    }

    /**
     * Handle OAuth2 authentication
     */
    protected function handleOAuth2Authentication(Request $request, Closure $next, string $authHeader)
    {
        try {
            $token = Str::substr($authHeader, 7);

            Log::info('OAuth2 authentication attempt', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'endpoint' => $request->path(),
            ]);

            // Validate token with identity service
            $user = $this->validateTokenWithIdentityService($token);
            if (! $user) {
                Log::warning('OAuth2 authentication failed: Invalid or expired token', [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'endpoint' => $request->path(),
                ]);
                return $this->unauthorizedResponse('Invalid or expired token');
            }

            Log::info('OAuth2 authentication successful', [
                'user_id' => $user['id'] ?? 'N/A',
                'role' => $user['role'] ?? 'N/A',
                'tenant_id' => $user['tenant_id'] ?? 'N/A',
                'scopes' => $user['scopes'] ?? [],
            ]);

            // Validate tenant context
            $tenantValidation = $this->validateTenantContext($request, $user);
            if (! $tenantValidation['valid']) {
                return $this->unauthorizedResponse($tenantValidation['message']);
            }

            // Set authenticated user on request
            $request->merge(['user' => $user]);

            // Set tenant context (skip for super admin)
            if (isset($user['role']) && $user['role'] === 'super_admin') {
                $request->merge(['tenant_id' => null]);
            } else {
                $tenantId = $user['tenant_id'];

                // Get tenant information from database
                $tenant = DB::connection('pgsql')
                    ->table('tenants')
                    ->where('id', $tenantId)
                    ->where('is_active', true)
                    ->first();

                $request->merge([
                    'tenant_id' => $tenantId,
                    'tenant_domain' => $tenant->domain ?? null,
                    'tenant_name' => $tenant->name ?? null,
                ]);
                $this->hybridDatabaseService->switchToTenantDatabase($tenantId);
            }

            // Set auth_user in request for downstream middleware
            $request->merge(['auth_user' => $user]);

            // Add scopes to request for scope middleware
            if (isset($user['scopes'])) {
                $request->merge(['user_scopes' => $user['scopes']]);
            }

            // Set the authenticated user in Laravel's auth system
            $this->setAuthenticatedUser($request, $user);

            // Log successful authentication
            $this->logAuthenticationSuccess($request, $user);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('OAuth2 authentication error: '.$e->getMessage(), ['exception' => $e]);

            return $this->unauthorizedResponse('OAuth2 authentication failed');
        }
    }

    /**
     * Validate token with identity service
     */
    protected function validateTokenWithIdentityService(string $token): ?array
    {
        // If we're in the identity service, validate token locally
        if (config('app.service_name') === 'identity-service') {
            return $this->validateTokenLocally($token);
        }

        $identityServiceUrl = Config::get('services.identity_service.url');
        Log::info('Token validation debug', [
            'service_name' => config('app.service_name'),
            'identity_service_url' => $identityServiceUrl,
        ]);
        
        if (! $identityServiceUrl) {
            Log::error('IDENTITY_SERVICE_URL is not configured.');

            return null;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ])->post($identityServiceUrl.'/api/v1/auth/validate-token');

            Log::info('Token validation HTTP response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'response_body' => $response->body(),
            ]);

            if ($response->successful()) {
                $userData = $response->json('data.user');
                Log::info('Token validation successful', [
                    'user_data' => $userData,
                ]);
                return $userData;
            }

            Log::warning('Token validation failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Error validating token with identity service: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Validate token locally (for identity service)
     */
    protected function validateTokenLocally(string $token): ?array
    {
        try {
            // Decode JWT to get the JTI (token ID)
            $payload = $this->decodeJwtPayload($token);
            if (! $payload || ! isset($payload['jti'])) {
                Log::warning('OAuth2 token validation failed: Invalid JWT payload');
                return null;
            }

            $tokenId = $payload['jti'];
            Log::debug('OAuth2 token validation: JWT decoded', [
                'jti' => $tokenId,
                'sub' => $payload['sub'] ?? 'N/A',
            ]);

            // Use Passport to validate the token
            $tokenModel = \Laravel\Passport\Token::where('id', $tokenId)->first();
            if (! $tokenModel || $tokenModel->revoked) {
                Log::warning('OAuth2 token validation failed: Token not found or revoked', [
                    'token_id' => $tokenId,
                    'found' => $tokenModel ? 'YES' : 'NO',
                    'revoked' => $tokenModel?->revoked ?? 'N/A',
                ]);
                return null;
            }

            // Get user from token with role relationship loaded
            $user = \App\Models\User::with('role')->find($tokenModel->user_id);
            if (! $user || ! $user->is_active) {
                Log::warning('OAuth2 token validation failed: User not found or inactive', [
                    'user_id' => $tokenModel->user_id,
                    'user_found' => $user ? 'YES' : 'NO',
                    'user_active' => $user?->is_active ?? 'N/A',
                ]);
                return null;
            }

            // Get token scopes from the token model
            $tokenScopes = $tokenModel->scopes ?? [];
            
            Log::debug('OAuth2 token validation successful', [
                'user_id' => $user->id,
                'user_role' => $user->role?->code ?? 'N/A',
                'tenant_id' => $user->tenant_id,
                'scopes' => $tokenScopes,
            ]);
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->code ?? 'tenant_admin',
                'tenant_id' => $user->tenant_id,
                'is_active' => $user->is_active,
                'scopes' => $tokenScopes,
            ];
        } catch (\Exception $e) {
            Log::error('Error validating token locally: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Decode JWT payload
     */
    protected function decodeJwtPayload(string $token): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = $parts[1];
            $payload = str_replace(['-', '_'], ['+', '/'], $payload);
            $payload = base64_decode($payload);

            return json_decode($payload, true);
        } catch (\Exception $e) {
            Log::error('Error decoding JWT payload: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Check if API key format is valid
     */
    protected function isValidApiKeyFormat(string $apiKey): bool
    {
        return Str::startsWith($apiKey, 'ak_') && strlen($apiKey) === 67;
    }

    /**
     * Return an unauthorized response
     */
    protected function unauthorizedResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
        ], 401);
    }

    /**
     * Return a server error response
     */
    protected function serverErrorResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }


    /**
     * Validate tenant context for OAuth2 authentication
     */
    protected function validateTenantContext(Request $request, array $user): array
    {
        // Super admin users don't need tenant validation
        if (isset($user['role']) && $user['role'] === 'super_admin') {
            return ['valid' => true, 'message' => 'Super admin access granted'];
        }

        // Check if user has tenant_id
        if (! isset($user['tenant_id']) || empty($user['tenant_id'])) {
            Log::warning('OAuth2 authentication: User missing tenant_id', [
                'user_id' => $user['id'] ?? 'N/A',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return [
                'valid' => false,
                'message' => 'Tenant ID not found in token payload',
            ];
        }

        // Get tenant domain from database using tenant ID (with caching)
        $tenant = $this->databaseOptimizationService->getCachedTenant($user['tenant_id']);

        if (! $tenant) {
            Log::warning('OAuth2 authentication: Tenant not found or inactive', [
                'user_id' => $user['id'] ?? 'N/A',
                'tenant_id' => $user['tenant_id'],
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => 'Tenant not found or inactive',
            ];
        }

        // CRITICAL: Validate HRMS-Client-ID header matches user's tenant_id
        $requestedTenantId = $request->header('HRMS-Client-ID');
        
        if (!$requestedTenantId) {
            Log::warning('OAuth2 authentication: HRMS-Client-ID header missing', [
                'user_id' => $user['id'] ?? 'N/A',
                'tenant_id' => $user['tenant_id'],
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => 'HRMS-Client-ID header is required',
            ];
        }

        if ($requestedTenantId !== $user['tenant_id']) {
            Log::warning('OAuth2 authentication: HRMS-Client-ID header mismatch', [
                'user_id' => $user['id'] ?? 'N/A',
                'user_tenant_id' => $user['tenant_id'],
                'requested_tenant_id' => $requestedTenantId,
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => "Token is valid for tenant '{$user['tenant_id']}' but requested tenant '{$requestedTenantId}'",
            ];
        }

        // Set tenant domain from database
        $requestedTenantDomain = $tenant->domain;

        // Optional: Validate X-Tenant-Domain header if provided (for backward compatibility)
        $headerTenantDomain = $request->header('X-Tenant-Domain');
        if ($headerTenantDomain && $headerTenantDomain !== $requestedTenantDomain) {
            Log::warning('OAuth2 authentication: X-Tenant-Domain header mismatch', [
                'user_id' => $user['id'] ?? 'N/A',
                'tenant_id' => $user['tenant_id'],
                'expected_domain' => $requestedTenantDomain,
                'provided_domain' => $headerTenantDomain,
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => "Token is valid for '{$requestedTenantDomain}' but requested '{$headerTenantDomain}'",
            ];
        }

        return ['valid' => true, 'message' => 'Tenant validation successful'];
    }

    /**
     * Validate that tenant domain matches user's tenant
     */
    protected function validateTenantDomainMatch(string $userTenantId, string $requestedTenantDomain): array
    {
        try {
            // Get tenant information from central database
            $tenant = DB::connection('pgsql')
                ->table('tenants')
                ->where('id', $userTenantId)
                ->where('is_active', true)
                ->first();

            if (! $tenant) {
                return [
                    'valid' => false,
                    'message' => 'User tenant not found or inactive',
                ];
            }

            // Check if requested domain matches user's tenant domain
            if ($tenant->domain !== $requestedTenantDomain) {
                return [
                    'valid' => false,
                    'message' => "Token is valid for '{$tenant->domain}' but requested '{$requestedTenantDomain}'",
                ];
            }

            return ['valid' => true, 'message' => 'Tenant domain matches'];
        } catch (\Exception $e) {
            Log::error('Error validating tenant domain match: '.$e->getMessage(), [
                'user_tenant_id' => $userTenantId,
                'requested_domain' => $requestedTenantDomain,
                'exception' => $e,
            ]);

            return [
                'valid' => false,
                'message' => 'Error validating tenant access',
            ];
        }
    }

    /**
     * Set the authenticated user in Laravel's auth system
     */
    protected function setAuthenticatedUser(Request $request, array $user): void
    {
        // Create a generic user object for Laravel's auth system
        $userModel = new class
        {
            public $id;

            public $name;

            public $email;

            public $role;

            public $tenant_id;

            public $is_active;

            public function toArray()
            {
                return [
                    'id' => $this->id,
                    'name' => $this->name,
                    'email' => $this->email,
                    'role' => $this->role,
                    'tenant_id' => $this->tenant_id,
                    'is_active' => $this->is_active,
                ];
            }
        };

        $userModel->id = $user['id'];
        $userModel->name = $user['name'];
        $userModel->email = $user['email'];
        $userModel->role = $user['role'];
        $userModel->tenant_id = $user['tenant_id'] ?? null;
        $userModel->is_active = $user['is_active'] ?? true;

        // Set the user in the request
        $request->setUserResolver(function () use ($userModel) {
            return $userModel;
        });
    }

    /**
     * Log successful authentication
     */
    protected function logAuthenticationSuccess(Request $request, array $user): void
    {
        try {
            app(\Shared\Services\AuditLogService::class)->logAuthenticationEvent(
                'oauth2_authentication_success',
                $user['id'] ?? null,
                $user['tenant_id'] ?? null,
                $request,
                [
                    'authentication_method' => 'oauth2',
                    'tenant_domain' => $request->header('X-Tenant-Domain', 'derived_from_tenant_id'),
                    'user_role' => $user['role'] ?? 'unknown',
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log authentication success: '.$e->getMessage());
        }
    }

    /**
     * Validate tenant context for API key authentication
     */
    protected function validateApiKeyTenantContext(Request $request, array $apiKeyData): array
    {
        // Check if API key has tenant_id
        if (! isset($apiKeyData['tenant_id']) || empty($apiKeyData['tenant_id'])) {
            Log::warning('API key authentication: API key missing tenant_id', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return [
                'valid' => false,
                'message' => 'API key tenant ID not found',
            ];
        }

        // Get tenant domain from database using tenant ID (optimization: no need for X-Tenant-Domain header)
        $tenant = DB::connection('pgsql')
            ->table('tenants')
            ->where('id', $apiKeyData['tenant_id'])
            ->where('is_active', true)
            ->first();

        if (! $tenant) {
            Log::warning('API key authentication: Tenant not found or inactive', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'tenant_id' => $apiKeyData['tenant_id'],
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => 'Tenant not found or inactive',
            ];
        }

        // CRITICAL: Validate HRMS-Client-ID header matches API key's tenant_id
        $requestedTenantId = $request->header('HRMS-Client-ID');
        
        if (!$requestedTenantId) {
            Log::warning('API key authentication: HRMS-Client-ID header missing', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'tenant_id' => $apiKeyData['tenant_id'],
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => 'HRMS-Client-ID header is required',
            ];
        }

        // SECURITY: Strict tenant validation to prevent cross-tenant access
        if ($requestedTenantId !== $apiKeyData['tenant_id']) {
            // Log security event for cross-tenant access attempt
            $this->securityService->logApiKeyEvent('api_key_cross_tenant_access_attempt', $apiKeyData, $request, [
                'api_key_tenant_id' => $apiKeyData['tenant_id'],
                'requested_tenant_id' => $requestedTenantId,
            ]);

            Log::warning('API key authentication: Cross-tenant access attempt blocked', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'api_key_tenant_id' => $apiKeyData['tenant_id'],
                'requested_tenant_id' => $requestedTenantId,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return [
                'valid' => false,
                'message' => "Access denied: API key belongs to different tenant",
            ];
        }

        // Set tenant domain from database
        $requestedTenantDomain = $tenant->domain;

        // Optional: Validate X-Tenant-Domain header if provided (for backward compatibility)
        $headerTenantDomain = $request->header('X-Tenant-Domain');

        if ($headerTenantDomain && $headerTenantDomain !== $requestedTenantDomain) {
            Log::warning('API key authentication: X-Tenant-Domain header mismatch', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'tenant_id' => $apiKeyData['tenant_id'],
                'expected_domain' => $requestedTenantDomain,
                'provided_domain' => $headerTenantDomain,
                'ip' => $request->ip(),
            ]);

            return [
                'valid' => false,
                'message' => "API key is valid for '{$requestedTenantDomain}' but requested '{$headerTenantDomain}'",
            ];
        }

        return ['valid' => true, 'message' => 'API key tenant validation successful'];
    }

    /**
     * Log successful API key authentication
     */
    protected function logApiKeyAuthenticationSuccess(Request $request, array $apiKeyData): void
    {
        try {
            app(\Shared\Services\AuditLogService::class)->logAuthenticationEvent(
                'api_key_authentication_success',
                $apiKeyData['id'] ?? null,
                $apiKeyData['tenant_id'] ?? null,
                $request,
                [
                    'authentication_method' => 'api_key',
                    'tenant_domain' => $request->header('X-Tenant-Domain', 'derived_from_tenant_id'),
                    'api_key_name' => $apiKeyData['name'] ?? 'unknown',
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log API key authentication success: '.$e->getMessage());
        }
    }

    /**
     * Log security event
     */
    protected function logSecurityEvent(string $eventType, array $user, Request $request, array $context = []): void
    {
        try {
            app(\Shared\Services\AuditLogService::class)->logSecurityEvent(
                $eventType,
                'high',
                $user['id'] ?? null,
                $user['tenant_id'] ?? null,
                $request,
                $context
            );
        } catch (\Exception $e) {
            Log::error('Failed to log security event: '.$e->getMessage());
        }
    }
}
