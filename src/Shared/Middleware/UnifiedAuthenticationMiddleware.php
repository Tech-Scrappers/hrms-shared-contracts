<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Shared\Services\ApiKeyService;
use Shared\Services\HybridDatabaseService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class UnifiedAuthenticationMiddleware
{
    protected $apiKeyService;
    protected $hybridDatabaseService;

    public function __construct(ApiKeyService $apiKeyService, HybridDatabaseService $hybridDatabaseService)
    {
        $this->apiKeyService = $apiKeyService;
        $this->hybridDatabaseService = $hybridDatabaseService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Try API key authentication first
            $apiKey = $request->header('X-API-Key');
            if ($apiKey) {
                return $this->handleApiKeyAuthentication($request, $next, $apiKey);
            }

            // Try OAuth2 authentication
            $authHeader = $request->header('Authorization');
            if ($authHeader && Str::startsWith($authHeader, 'Bearer ')) {
                return $this->handleOAuth2Authentication($request, $next, $authHeader);
            }

            return $this->unauthorizedResponse('Authentication required. Provide either X-API-Key header or Authorization Bearer token.');
        } catch (\Exception $e) {
            Log::error('UnifiedAuthenticationMiddleware error: ' . $e->getMessage(), ['exception' => $e]);
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
            if (!$this->isValidApiKeyFormat($apiKey)) {
                return $this->unauthorizedResponse('Invalid API key format');
            }

            // Get API key details
            $apiKeyData = $this->apiKeyService->validateApiKey($apiKey);
            if (!$apiKeyData) {
                return $this->unauthorizedResponse('Invalid API key');
            }

            // Check if API key is active
            if (!$apiKeyData['is_active']) {
                return $this->unauthorizedResponse('API key is inactive');
            }

            // CRITICAL FIX: Validate tenant context for API key
            $tenantValidation = $this->validateApiKeyTenantContext($request, $apiKeyData);
            if (!$tenantValidation['valid']) {
                // Log security event for cross-tenant API key access attempt
                $this->logSecurityEvent('api_key_cross_tenant_access_attempt', $apiKeyData, $request, [
                    'requested_tenant_domain' => $request->header('X-Tenant-Domain'),
                    'api_key_tenant_id' => $apiKeyData['tenant_id'],
                    'reason' => $tenantValidation['message']
                ]);
                
                return $this->unauthorizedResponse($tenantValidation['message']);
            }

            // Set tenant context
            $tenantId = $apiKeyData['tenant_id'];
            $request->merge(['tenant_id' => $tenantId]);
            $this->hybridDatabaseService->switchToTenantDatabase($tenantId);

            // Set authenticated API key on request
            $request->merge(['api_key' => $apiKeyData]);

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
            Log::error('API key authentication error: ' . $e->getMessage(), ['exception' => $e]);
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
            
            // Validate token with identity service
            $user = $this->validateTokenWithIdentityService($token);
            if (!$user) {
                return $this->unauthorizedResponse('Invalid or expired token');
            }

            // Validate tenant context
            $tenantValidation = $this->validateTenantContext($request, $user);
            if (!$tenantValidation['valid']) {
                return $this->unauthorizedResponse($tenantValidation['message']);
            }

            // Set authenticated user on request
            $request->merge(['user' => $user]);

            // Set tenant context (skip for super admin)
            if (isset($user['role']) && $user['role'] === 'super_admin') {
                $request->merge(['tenant_id' => null]);
            } else {
                $tenantId = $user['tenant_id'];
                $request->merge(['tenant_id' => $tenantId]);
                $this->hybridDatabaseService->switchToTenantDatabase($tenantId);
            }

            // Set auth_user in request for downstream middleware
            $request->merge(['auth_user' => $user]);

            // Set the authenticated user in Laravel's auth system
            $this->setAuthenticatedUser($request, $user);

            // Log successful authentication
            $this->logAuthenticationSuccess($request, $user);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('OAuth2 authentication error: ' . $e->getMessage(), ['exception' => $e]);
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
        if (!$identityServiceUrl) {
            Log::error('IDENTITY_SERVICE_URL is not configured.');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ])->post($identityServiceUrl . '/api/v1/auth/validate-token');

            if ($response->successful()) {
                return $response->json('data.user');
            }

            Log::warning('Token validation failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error('Error validating token with identity service: ' . $e->getMessage(), ['exception' => $e]);
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
            if (!$payload || !isset($payload['jti'])) {
                return null;
            }

            $tokenId = $payload['jti'];

            // Use Passport to validate the token
            $tokenModel = \Laravel\Passport\Token::where('id', $tokenId)->first();
            if (!$tokenModel || $tokenModel->revoked) {
                return null;
            }

            // Get user from token
            $user = \App\Models\User::find($tokenModel->user_id);
            if (!$user || !$user->is_active) {
                return null;
            }

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $user->tenant_id,
                'is_active' => $user->is_active,
            ];
        } catch (\Exception $e) {
            Log::error('Error validating token locally: ' . $e->getMessage(), ['exception' => $e]);
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
            Log::error('Error decoding JWT payload: ' . $e->getMessage());
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
        if (!isset($user['tenant_id']) || empty($user['tenant_id'])) {
            Log::warning('OAuth2 authentication: User missing tenant_id', [
                'user_id' => $user['id'] ?? 'N/A',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return [
                'valid' => false,
                'message' => 'Tenant ID not found in token payload'
            ];
        }

        // Get requested tenant domain
        $requestedTenantDomain = $request->header('X-Tenant-Domain');
        if (!$requestedTenantDomain) {
            Log::warning('OAuth2 authentication: Missing X-Tenant-Domain header', [
                'user_id' => $user['id'] ?? 'N/A',
                'tenant_id' => $user['tenant_id'],
                'ip' => $request->ip(),
            ]);
            return [
                'valid' => false,
                'message' => 'Tenant domain header is required'
            ];
        }

        // Validate tenant domain matches user's tenant
        $tenantValidation = $this->validateTenantDomainMatch($user['tenant_id'], $requestedTenantDomain);
        if (!$tenantValidation['valid']) {
            // Log security event for cross-tenant access attempt
            $this->logSecurityEvent('cross_tenant_access_attempt', $user, $request, [
                'requested_tenant_domain' => $requestedTenantDomain,
                'user_tenant_id' => $user['tenant_id'],
                'reason' => $tenantValidation['message']
            ]);
            
            return [
                'valid' => false,
                'message' => 'Access denied: Token is not valid for the requested tenant'
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

            if (!$tenant) {
                return [
                    'valid' => false,
                    'message' => 'User tenant not found or inactive'
                ];
            }

            // Check if requested domain matches user's tenant domain
            if ($tenant->domain !== $requestedTenantDomain) {
                return [
                    'valid' => false,
                    'message' => "Token is valid for '{$tenant->domain}' but requested '{$requestedTenantDomain}'"
                ];
            }

            return ['valid' => true, 'message' => 'Tenant domain matches'];
        } catch (\Exception $e) {
            Log::error('Error validating tenant domain match: ' . $e->getMessage(), [
                'user_tenant_id' => $userTenantId,
                'requested_domain' => $requestedTenantDomain,
                'exception' => $e
            ]);
            
            return [
                'valid' => false,
                'message' => 'Error validating tenant access'
            ];
        }
    }

    /**
     * Set the authenticated user in Laravel's auth system
     */
    protected function setAuthenticatedUser(Request $request, array $user): void
    {
        // Create a generic user object for Laravel's auth system
        $userModel = new class {
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
                    'tenant_domain' => $request->header('X-Tenant-Domain'),
                    'user_role' => $user['role'] ?? 'unknown'
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log authentication success: ' . $e->getMessage());
        }
    }

    /**
     * Validate tenant context for API key authentication
     */
    protected function validateApiKeyTenantContext(Request $request, array $apiKeyData): array
    {
        // Check if API key has tenant_id
        if (!isset($apiKeyData['tenant_id']) || empty($apiKeyData['tenant_id'])) {
            Log::warning('API key authentication: API key missing tenant_id', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return [
                'valid' => false,
                'message' => 'API key tenant ID not found'
            ];
        }

        // Get requested tenant domain
        $requestedTenantDomain = $request->header('X-Tenant-Domain');
        if (!$requestedTenantDomain) {
            Log::warning('API key authentication: Missing X-Tenant-Domain header', [
                'api_key_id' => $apiKeyData['id'] ?? 'N/A',
                'tenant_id' => $apiKeyData['tenant_id'],
                'ip' => $request->ip(),
            ]);
            return [
                'valid' => false,
                'message' => 'Tenant domain header is required'
            ];
        }

        // Validate tenant domain matches API key's tenant
        $tenantValidation = $this->validateTenantDomainMatch($apiKeyData['tenant_id'], $requestedTenantDomain);
        if (!$tenantValidation['valid']) {
            return [
                'valid' => false,
                'message' => 'Access denied: API key is not valid for the requested tenant'
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
                    'tenant_domain' => $request->header('X-Tenant-Domain'),
                    'api_key_name' => $apiKeyData['name'] ?? 'unknown'
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to log API key authentication success: ' . $e->getMessage());
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
            Log::error('Failed to log security event: ' . $e->getMessage());
        }
    }
}
