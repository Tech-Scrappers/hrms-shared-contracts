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
     *
     * @param Request $request
     * @param Closure $next
     * @param string $scope
     * @return Response|\Illuminate\Http\JsonResponse
     */
    public function handle(Request $request, Closure $next, string $scope): Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();
            
            if (!$user) {
                Log::warning('ScopeMiddleware: No authenticated user found', [
                    'required_scope' => $scope,
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);
                
                return $this->unauthorizedResponse('Authentication required');
            }

            // Get the token
            $token = $user->token();
            if (!$token) {
                Log::warning('ScopeMiddleware: No token found for user', [
                    'user_id' => $user->id,
                    'required_scope' => $scope,
                    'request_uri' => $request->getRequestUri(),
                ]);
                
                return $this->unauthorizedResponse('Valid token required');
            }

            // Check if token has the required scope
            $tokenScopes = $token->scopes ?? [];
            if (!in_array($scope, $tokenScopes)) {
                Log::warning('ScopeMiddleware: Access denied - missing required scope', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'required_scope' => $scope,
                    'token_scopes' => $tokenScopes,
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
}
