<?php

namespace Shared\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Shared\Services\ApiKeyService;

class ApiKeyPermissionMiddleware
{
    public function __construct(
        private ApiKeyService $apiKeyService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $permission): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
    {
        try {
            // Get API key from request
            $apiKey = $this->extractApiKey($request);

            if (! $apiKey) {
                return $this->forbiddenResponse('API key is required');
            }

            // Check if API key has the required permission
            if (! $this->apiKeyService->hasPermission($apiKey, $permission)) {
                Log::warning('API key permission denied', [
                    'api_key' => substr($apiKey, 0, 10).'...',
                    'required_permission' => $permission,
                    'request_uri' => $request->getRequestUri(),
                    'ip' => $request->ip(),
                ]);

                return $this->forbiddenResponse("Insufficient permissions. Required: {$permission}");
            }

            // Add permission context to request
            $request->merge([
                'required_permission' => $permission,
            ]);

            return $next($request);

        } catch (Exception $e) {
            Log::error('API key permission check error', [
                'error' => $e->getMessage(),
                'permission' => $permission,
                'request_uri' => $request->getRequestUri(),
            ]);

            return $this->serverErrorResponse('Permission check service error');
        }
    }

    /**
     * Extract API key from request
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check Authorization header (Bearer token format)
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Check HRMS-Client-Secret header
        $apiKeyHeader = $request->header('HRMS-Client-Secret');
        if ($apiKeyHeader) {
            return $apiKeyHeader;
        }

        // Check query parameter
        $apiKeyParam = $request->query('api_key');
        if ($apiKeyParam) {
            return $apiKeyParam;
        }

        return null;
    }

    /**
     * Return forbidden response
     *
     * @return Response
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
    private function serverErrorResponse(string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }
}
