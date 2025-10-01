<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class OAuth2TokenValidationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            \Log::info('OAuth2 Token Validation Middleware Called', [
                'path' => $request->path(),
                'method' => $request->method(),
                'authorization_header' => $request->header('Authorization'),
            ]);

            // Get the token from the Authorization header
            $token = $this->extractToken($request);

            \Log::info('OAuth2 Token Validation Debug', [
                'authorization_header' => $request->header('Authorization'),
                'has_token' => $token ? 'yes' : 'no',
                'all_headers' => $request->headers->all(),
            ]);

            if (! $token) {
                return $this->unauthorizedResponse('Authorization token is required');
            }

            // Validate the token with the identity service
            $user = $this->validateTokenWithIdentityService($token);

            if (! $user) {
                return $this->unauthorizedResponse('Invalid or expired token');
            }

            // Add user information to the request
            $request->merge([
                'auth_user' => $user,
                'user_id' => $user['id'] ?? null,
            ]);

            // Set tenant context if available
            if (isset($user['tenant_id'])) {
                $request->merge(['tenant_id' => $user['tenant_id']]);

                // Switch to tenant database
                $tenantDatabaseService = app(\Shared\Services\TenantDatabaseService::class);
                $tenantDatabaseService->switchToTenantDatabase($user['tenant_id']);
            }

            return $next($request);

        } catch (\Exception $e) {
            Log::error('OAuth2 Token Validation Error: '.$e->getMessage(), ['exception' => $e]);

            return $this->serverErrorResponse('An internal server error occurred during token validation.');
        }
    }

    /**
     * Extract token from request
     */
    private function extractToken(Request $request): ?string
    {
        $authorization = $request->header('Authorization');

        if (! $authorization) {
            return null;
        }

        // Check for Bearer token
        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return null;
    }

    /**
     * Validate token with identity service
     */
    private function validateTokenWithIdentityService(string $token): ?array
    {
        try {
            $identityServiceUrl = config('services.identity_service.url', 'http://localhost:8001');

            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ])
                ->get($identityServiceUrl.'/api/v1/auth/me');

            if ($response->successful()) {
                $data = $response->json();

                return $data['data']['user'] ?? null;
            }

            Log::warning('Token validation failed', [
                'status' => $response->status(),
                'response' => $response->body(),
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('Error validating token with identity service', [
                'error' => $e->getMessage(),
                'endpoint' => $request->path(),
            ]);

            return null;
        }
    }

    /**
     * Create unauthorized response
     */
    private function unauthorizedResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'UNAUTHORIZED',
        ], 401);
    }

    /**
     * Create server error response
     */
    private function serverErrorResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'INTERNAL_SERVER_ERROR',
        ], 500);
    }
}
