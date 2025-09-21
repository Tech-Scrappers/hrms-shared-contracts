<?php

namespace Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait EnterpriseApiResponseTrait
{
    /**
     * Generate a standardized success response following enterprise standards
     */
    protected function successResponse(
        $data = null, 
        string $message = 'Success', 
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'status' => $statusCode,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
                'request_id' => $this->getRequestId(),
                'version' => 'v1',
                'execution_time_ms' => $this->getExecutionTime(),
            ], $meta)
        ];

        // Add pagination meta if present
        if (isset($meta['pagination'])) {
            $response['meta']['pagination'] = $meta['pagination'];
        }

        // Add filters applied if present
        if (isset($meta['filters_applied'])) {
            $response['meta']['filters_applied'] = $meta['filters_applied'];
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Generate a standardized created response (201)
     */
    protected function createdResponse(
        $data = null, 
        string $message = 'Resource created successfully',
        string $location = null
    ): JsonResponse {
        $meta = [
            'timestamp' => now()->toISOString(),
            'request_id' => $this->getRequestId(),
            'version' => 'v1',
            'execution_time_ms' => $this->getExecutionTime(),
        ];

        if ($location) {
            $meta['location'] = $location;
        }

        return response()->json([
            'status' => 201,
            'message' => $message,
            'data' => $data,
            'meta' => $meta
        ], 201);
    }

    /**
     * Generate a standardized no content response (204)
     */
    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Generate a standardized error response following enterprise standards
     */
    protected function errorResponse(
        string $message,
        int $statusCode = 400,
        string $errorCode = null,
        string $errorType = 'client_error',
        array $details = [],
        array $meta = []
    ): JsonResponse {
        $response = [
            'status' => $statusCode,
            'error' => [
                'code' => $errorCode ?: $this->getDefaultErrorCode($statusCode),
                'message' => $message,
                'type' => $errorType,
            ],
            'meta' => array_merge([
                'timestamp' => now()->toISOString(),
                'request_id' => $this->getRequestId(),
                'version' => 'v1',
            ], $meta)
        ];

        // Add error details if provided
        if (!empty($details)) {
            $response['error']['details'] = $details;
        }

        // Add specific error information based on status code
        switch ($statusCode) {
            case 400:
                $response['meta']['documentation_url'] = config('app.url') . '/api/v1/docs/errors#bad-request';
                break;
            case 401:
                $response['meta']['authentication_schemes'] = ['Bearer', 'API-Key'];
                break;
            case 403:
                $response['meta']['required_permissions'] = $details['required_permissions'] ?? [];
                break;
            case 404:
                $response['meta']['resource_type'] = $details['resource_type'] ?? 'resource';
                $response['meta']['resource_id'] = $details['resource_id'] ?? null;
                break;
            case 409:
                $response['meta']['conflicting_field'] = $details['conflicting_field'] ?? null;
                break;
            case 422:
                $response['error']['validation_errors'] = $details['validation_errors'] ?? [];
                break;
            case 429:
                $response['meta']['rate_limit'] = $details['rate_limit'] ?? [];
                break;
            case 500:
                $response['meta']['incident_id'] = 'INC-' . now()->format('Y-m-d') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                break;
            case 502:
                $response['meta']['service_status_url'] = config('app.url') . '/status';
                break;
            case 503:
                $response['meta']['estimated_recovery'] = now()->addHours(2)->toISOString();
                $response['meta']['maintenance_info'] = config('app.url') . '/status/maintenance';
                break;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Generate validation error response (422)
     */
    protected function validationErrorResponse(
        array $validationErrors,
        string $message = 'The request was well-formed but contains semantic errors'
    ): JsonResponse {
        $formattedErrors = [];
        
        foreach ($validationErrors as $field => $errors) {
            foreach ($errors as $error) {
                $formattedErrors[] = [
                    'field' => $field,
                    'code' => $this->getValidationErrorCode($error),
                    'message' => $error,
                    'rejected_value' => '[REDACTED]' // Never expose actual values
                ];
            }
        }

        return $this->errorResponse(
            $message,
            422,
            'VALIDATION_FAILED',
            'validation_error',
            ['validation_errors' => $formattedErrors]
        );
    }

    /**
     * Generate authentication error response (401)
     */
    protected function authenticationErrorResponse(
        string $message = 'Valid authentication credentials are required'
    ): JsonResponse {
        return $this->errorResponse(
            $message,
            401,
            'AUTHENTICATION_REQUIRED',
            'authentication_error'
        );
    }

    /**
     * Generate authorization error response (403)
     */
    protected function authorizationErrorResponse(
        string $message = 'You do not have permission to access this resource',
        array $requiredPermissions = []
    ): JsonResponse {
        return $this->errorResponse(
            $message,
            403,
            'INSUFFICIENT_PERMISSIONS',
            'authorization_error',
            ['required_permissions' => $requiredPermissions]
        );
    }

    /**
     * Generate not found error response (404)
     */
    protected function notFoundErrorResponse(
        string $message = 'The requested resource could not be found',
        string $resourceType = 'resource',
        $resourceId = null
    ): JsonResponse {
        return $this->errorResponse(
            $message,
            404,
            'RESOURCE_NOT_FOUND',
            'client_error',
            [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId
            ]
        );
    }

    /**
     * Generate conflict error response (409)
     */
    protected function conflictErrorResponse(
        string $message = 'The request conflicts with the current state of the resource',
        string $conflictingField = null,
        string $details = null
    ): JsonResponse {
        $errorDetails = [];
        if ($conflictingField) {
            $errorDetails['conflicting_field'] = $conflictingField;
        }
        if ($details) {
            $errorDetails['details'] = $details;
        }

        return $this->errorResponse(
            $message,
            409,
            'RESOURCE_CONFLICT',
            'client_error',
            $errorDetails
        );
    }

    /**
     * Generate rate limit error response (429)
     */
    protected function rateLimitErrorResponse(
        int $limit,
        int $remaining,
        int $resetTime,
        int $retryAfter = 3600
    ): JsonResponse {
        return $this->errorResponse(
            'Too many requests. Please try again later',
            429,
            'RATE_LIMIT_EXCEEDED',
            'rate_limit_error',
            [
                'rate_limit' => [
                    'limit' => $limit,
                    'remaining' => $remaining,
                    'reset_time' => now()->addSeconds($resetTime)->toISOString(),
                    'retry_after' => $retryAfter
                ]
            ]
        );
    }

    /**
     * Generate server error response (500)
     */
    protected function serverErrorResponse(
        string $message = 'An unexpected error occurred. Please try again later'
    ): JsonResponse {
        return $this->errorResponse(
            $message,
            500,
            'INTERNAL_SERVER_ERROR',
            'server_error'
        );
    }

    /**
     * Generate service unavailable response (503)
     */
    protected function serviceUnavailableResponse(
        string $message = 'Service is temporarily unavailable due to maintenance'
    ): JsonResponse {
        return $this->errorResponse(
            $message,
            503,
            'SERVICE_UNAVAILABLE',
            'server_error'
        );
    }

    /**
     * Get request ID from headers or generate new one
     */
    private function getRequestId(): string
    {
        $request = request();
        return $request->header('X-Request-ID') ?: 
               $request->header('X-Correlation-ID') ?: 
               (string) Str::uuid();
    }

    /**
     * Get execution time in milliseconds
     */
    private function getExecutionTime(): int
    {
        $startTime = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        return (int) ((microtime(true) - $startTime) * 1000);
    }

    /**
     * Get default error code based on status code
     */
    private function getDefaultErrorCode(int $statusCode): string
    {
        return match($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'AUTHENTICATION_REQUIRED',
            403 => 'INSUFFICIENT_PERMISSIONS',
            404 => 'RESOURCE_NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'RESOURCE_CONFLICT',
            422 => 'VALIDATION_FAILED',
            429 => 'RATE_LIMIT_EXCEEDED',
            500 => 'INTERNAL_SERVER_ERROR',
            502 => 'UPSTREAM_SERVICE_ERROR',
            503 => 'SERVICE_UNAVAILABLE',
            default => 'UNKNOWN_ERROR'
        };
    }

    /**
     * Get validation error code from error message
     */
    private function getValidationErrorCode(string $error): string
    {
        return match(true) {
            str_contains($error, 'required') => 'REQUIRED',
            str_contains($error, 'email') => 'INVALID_FORMAT',
            str_contains($error, 'unique') => 'ALREADY_EXISTS',
            str_contains($error, 'min') => 'TOO_SHORT',
            str_contains($error, 'max') => 'TOO_LONG',
            str_contains($error, 'password') => 'TOO_WEAK',
            str_contains($error, 'date') => 'INVALID_DATE',
            str_contains($error, 'numeric') => 'INVALID_NUMBER',
            default => 'INVALID_VALUE'
        };
    }
}
