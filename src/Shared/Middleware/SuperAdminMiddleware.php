<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class SuperAdminMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Get the authenticated user
            $user = $request->user();

            if (! $user) {
                Log::warning('SuperAdminMiddleware: No authenticated user found', [
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);

                return $this->unauthorizedResponse('Authentication required');
            }

            // Check if user is super admin
            if (! $user->isSuperAdmin()) {
                Log::warning('SuperAdminMiddleware: Access denied - user is not super admin', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);

                return $this->forbiddenResponse('Super admin access required');
            }

            // Check if user has tenant-admin scope
            $token = $request->user()->token();
            if (! $token || ! in_array('tenant-admin', $token->scopes ?? [])) {
                Log::warning('SuperAdminMiddleware: Access denied - missing tenant-admin scope', [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'token_scopes' => $token->scopes ?? [],
                    'request_uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                ]);

                return $this->forbiddenResponse('Tenant admin scope required');
            }

            // Add super admin context to request
            $request->merge([
                'is_super_admin' => true,
                'admin_scope' => 'tenant-admin',
            ]);

            Log::info('SuperAdminMiddleware: Access granted', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method(),
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('SuperAdminMiddleware: Error occurred', [
                'error' => $e->getMessage(),
                'request_uri' => $request->getRequestUri(),
                'method' => $request->method(),
            ]);

            return $this->serverErrorResponse('Authorization check failed');
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
