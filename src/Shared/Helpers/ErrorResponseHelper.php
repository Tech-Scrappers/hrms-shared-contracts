<?php

namespace Shared\Helpers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ErrorResponseHelper
{
    /**
     * Standardized success response
     */
    public static function success(
        mixed $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString(),
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Standardized error response
     */
    public static function error(
        string $message = 'An error occurred',
        mixed $data = null,
        int $statusCode = 400,
        string $errorCode = null,
        array $errors = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => now()->toISOString(),
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Validation error response
     */
    public static function validationError(
        array $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 422,
            errorCode: 'VALIDATION_ERROR',
            errors: $errors
        );
    }

    /**
     * Unauthorized error response
     */
    public static function unauthorized(
        string $message = 'Unauthorized access'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 401,
            errorCode: 'UNAUTHORIZED'
        );
    }

    /**
     * Forbidden error response
     */
    public static function forbidden(
        string $message = 'Access forbidden'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 403,
            errorCode: 'FORBIDDEN'
        );
    }

    /**
     * Not found error response
     */
    public static function notFound(
        string $message = 'Resource not found'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 404,
            errorCode: 'NOT_FOUND'
        );
    }

    /**
     * Server error response
     */
    public static function serverError(
        string $message = 'Internal server error',
        Request $request = null
    ): JsonResponse {
        // Log server errors for debugging
        if ($request) {
            Log::error('Server error occurred', [
                'message' => $message,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'timestamp' => now()->toISOString(),
            ]);
        }

        return self::error(
            message: $message,
            statusCode: 500,
            errorCode: 'SERVER_ERROR'
        );
    }

    /**
     * Rate limit exceeded response
     */
    public static function rateLimited(
        string $message = 'Rate limit exceeded',
        int $retryAfter = 3600
    ): JsonResponse {
        $response = self::error(
            message: $message,
            statusCode: 429,
            errorCode: 'RATE_LIMITED'
        );

        $response->headers->set('Retry-After', $retryAfter);

        return $response;
    }

    /**
     * Method not allowed response
     */
    public static function methodNotAllowed(
        string $message = 'Method not allowed'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 405,
            errorCode: 'METHOD_NOT_ALLOWED'
        );
    }

    /**
     * Conflict error response
     */
    public static function conflict(
        string $message = 'Resource conflict'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 409,
            errorCode: 'CONFLICT'
        );
    }

    /**
     * Unprocessable entity response
     */
    public static function unprocessableEntity(
        string $message = 'Unprocessable entity',
        array $errors = []
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 422,
            errorCode: 'UNPROCESSABLE_ENTITY',
            errors: $errors
        );
    }

    /**
     * Service unavailable response
     */
    public static function serviceUnavailable(
        string $message = 'Service temporarily unavailable'
    ): JsonResponse {
        return self::error(
            message: $message,
            statusCode: 503,
            errorCode: 'SERVICE_UNAVAILABLE'
        );
    }

    /**
     * Handle exceptions with proper error responses
     */
    public static function handleException(
        \Throwable $exception,
        Request $request = null
    ): JsonResponse {
        // Log the exception
        Log::error('Exception occurred', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'url' => $request?->fullUrl(),
            'method' => $request?->method(),
            'ip' => $request?->ip(),
        ]);

        // Handle specific exception types
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return self::validationError(
                $exception->errors(),
                'Validation failed'
            );
        }

        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return self::unauthorized('Authentication required');
        }

        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return self::forbidden('Insufficient permissions');
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return self::notFound('Resource not found');
        }

        // Default to server error
        return self::serverError(
            'An unexpected error occurred',
            $request
        );
    }
}
