<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Token;

class ScopeMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $scope): Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();

            if (! $user) {
                Log::warning('ScopeMiddleware: No authenticated user found', [
                    'required_scope' => $scope,
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);

                return $this->unauthorizedResponse('Authentication required');
            }

            // Check if user is super admin (bypass scope check)
            if (is_array($user) && isset($user['role']) && $user['role'] === 'super_admin') {
                Log::debug('ScopeMiddleware: Super admin access granted', [
                    'user_id' => $user['id'] ?? 'N/A',
                    'required_scope' => $scope,
                ]);
                return $next($request);
            }

            // For OAuth2 authentication, check if user has the required scope
            $tokenScopes = $request->get('user_scopes', []);
            
            // If no scopes in request, try to get from auth_user
            if (empty($tokenScopes)) {
                $authUser = $request->get('auth_user', []);
                $tokenScopes = $authUser['scopes'] ?? [];
            }

            // For API key authentication, check permissions
            $apiKeyPermissions = $request->get('api_key_permissions', []);
            if (!empty($apiKeyPermissions)) {
                // API key with '*' permission has access to everything
                if (in_array('*', $apiKeyPermissions)) {
                    Log::debug('ScopeMiddleware: API key with full permissions granted access', [
                        'api_key_id' => $request->get('api_key_id', 'N/A'),
                        'required_scope' => $scope,
                    ]);
                    return $next($request);
                }

                // Check if API key has specific permission for the scope
                $scopePermission = $this->mapScopeToPermission($scope);
                if (in_array($scopePermission, $apiKeyPermissions)) {
                    Log::debug('ScopeMiddleware: API key permission granted', [
                        'api_key_id' => $request->get('api_key_id', 'N/A'),
                        'required_scope' => $scope,
                        'permission' => $scopePermission,
                    ]);
                    return $next($request);
                }
            }
            
            // Check OAuth2 scopes
            if (! in_array($scope, $tokenScopes)) {
                Log::warning('ScopeMiddleware: Access denied - missing required scope', [
                    'user_id' => is_array($user) ? ($user['id'] ?? 'N/A') : $user->id,
                    'user_role' => is_array($user) ? ($user['role'] ?? 'N/A') : ($user->role ?? 'N/A'),
                    'required_scope' => $scope,
                    'token_scopes' => $tokenScopes,
                    'api_key_permissions' => $apiKeyPermissions,
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);

                return $this->forbiddenResponse("Scope '{$scope}' is required");
            }

            // Add scope context to request
            $request->merge([
                'required_scope' => $scope,
                'user_scopes' => $tokenScopes,
            ]);

            Log::info('ScopeMiddleware: Access granted', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'required_scope' => $scope,
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method(),
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('ScopeMiddleware: Error occurred', [
                'error' => $e->getMessage(),
                'required_scope' => $scope,
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method(),
            ]);

            return $this->serverErrorResponse('Scope check failed');
        }
    }

    /**
     * Return unauthorized response
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
     * Return forbidden response
     */
    private function forbiddenResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }

    /**
     * Return server error response
     */
    private function serverErrorResponse(string $message): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }

    /**
     * Map OAuth2 scope to API key permission
     *
     * @param string $scope
     * @return string
     */
    private function mapScopeToPermission(string $scope): string
    {
        $mapping = [
            'api-keys' => 'api-keys',
            'employees:read' => 'employees:read',
            'employees:write' => 'employees:write',
            'attendance:read' => 'attendance:read',
            'attendance:write' => 'attendance:write',
            'reports:read' => 'reports:read',
        ];

        return $mapping[$scope] ?? $scope;
    }
}
