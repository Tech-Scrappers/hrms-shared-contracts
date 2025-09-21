<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class JsonResponseMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            
            // Ensure all responses are JSON for API routes
            if ($request->is('api/*')) {
                return $this->ensureJsonResponse($response);
            }
            
            return $response;
        } catch (\Exception $e) {
            Log::error('JsonResponseMiddleware error: ' . $e->getMessage(), [
                'exception' => $e,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);
            
            return $this->createErrorResponse($e);
        }
    }

    /**
     * Ensure response is JSON format
     */
    protected function ensureJsonResponse($response): JsonResponse
    {
        // If response is already JSON, return as is
        if ($response instanceof JsonResponse) {
            return $response;
        }

        // If response is a string and looks like HTML, convert to JSON error
        if (is_string($response) && $this->isHtmlResponse($response)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid request format',
                'error_code' => 'INVALID_REQUEST',
                'details' => 'Request must be properly formatted JSON'
            ], 400);
        }

        // If response is a string, wrap it in JSON
        if (is_string($response)) {
            return response()->json([
                'success' => true,
                'data' => $response
            ]);
        }

        // For other response types, convert to JSON
        return response()->json([
            'success' => true,
            'data' => $response
        ]);
    }

    /**
     * Check if response is HTML
     */
    protected function isHtmlResponse(string $response): bool
    {
        return str_contains($response, '<!DOCTYPE html>') || 
               str_contains($response, '<html') || 
               str_contains($response, '<head>');
    }

    /**
     * Create error response for exceptions
     */
    protected function createErrorResponse(\Exception $e): JsonResponse
    {
        $statusCode = 500;
        $message = 'Internal server error';
        $errorCode = 'SERVER_ERROR';

        // Handle specific exception types
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $statusCode = 422;
            $message = 'Validation failed';
            $errorCode = 'VALIDATION_ERROR';
        } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
            $statusCode = 401;
            $message = 'Authentication required';
            $errorCode = 'UNAUTHORIZED';
        } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            $statusCode = 403;
            $message = 'Access denied';
            $errorCode = 'FORBIDDEN';
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            $statusCode = 404;
            $message = 'Resource not found';
            $errorCode = 'NOT_FOUND';
        } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            $statusCode = 405;
            $message = 'Method not allowed';
            $errorCode = 'METHOD_NOT_ALLOWED';
        }

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'details' => config('app.debug') ? $e->getMessage() : 'An error occurred while processing your request'
        ], $statusCode);
    }
}
