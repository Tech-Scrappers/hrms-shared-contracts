<?php

namespace Shared\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shared\Enums\ApiErrorCode;
use Shared\Traits\EnterpriseApiResponseTrait;

/**
 * Centralized API Response Service
 * 
 * This service provides a single source of truth for all API responses
 * across the HRMS microservices system, ensuring consistency and
 * maintainability.
 */
class ApiResponseService
{
    use EnterpriseApiResponseTrait;

    private Request $request;
    private float $startTime;

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->startTime = microtime(true);
    }

    /**
     * Create a success response
     */
    public function success(
        $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return $this->successResponse($data, $message, $statusCode, $meta);
    }

    /**
     * Create a success response without data key
     */
    public function successNoData(
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return $this->successNoDataResponse($message, $statusCode, $meta);
    }

    /**
     * Create a created response (201)
     */
    public function created(
        $data = null,
        string $message = 'Resource created successfully',
        ?string $location = null
    ): JsonResponse {
        return $this->createdResponse($data, $message, $location);
    }

    /**
     * Create a created response (201) without data key
     */
    public function createdNoData(
        string $message = 'Resource created successfully',
        ?string $location = null
    ): JsonResponse {
        return $this->createdNoDataResponse($message, $location);
    }

    /**
     * Create a no content response (204)
     */
    public function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Create an error response using error codes
     */
    public function error(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = [],
        array $meta = []
    ): JsonResponse {
        $message = $message ?? $errorCode->getDescription();
        
        return $this->errorResponse(
            $message,
            $errorCode->getHttpStatusCode(),
            $errorCode->value,
            $errorCode->getErrorType(),
            $details,
            $meta
        );
    }

    /**
     * Create a validation error response
     */
    public function validationError(
        array $validationErrors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->error(
            ApiErrorCode::VALIDATION_FAILED,
            $message,
            ['validation_errors' => $validationErrors]
        );
    }

    /**
     * Create a not found response
     */
    public function notFound(
        string $message = 'Resource not found',
        string $resourceType = 'resource',
        string $resourceId = null
    ): JsonResponse {
        $details = [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ];

        return $this->error(
            ApiErrorCode::RESOURCE_NOT_FOUND,
            $message,
            $details
        );
    }

    /**
     * Create an unauthorized response
     */
    public function unauthorized(
        string $message = 'Authentication required'
    ): JsonResponse {
        return $this->error(
            ApiErrorCode::AUTHENTICATION_REQUIRED,
            $message
        );
    }

    /**
     * Create a forbidden response
     */
    public function forbidden(
        string $message = 'Insufficient permissions',
        array $requiredPermissions = []
    ): JsonResponse {
        $details = [];
        if (!empty($requiredPermissions)) {
            $details['required_permissions'] = $requiredPermissions;
        }

        return $this->error(
            ApiErrorCode::INSUFFICIENT_PERMISSIONS,
            $message,
            $details
        );
    }

    /**
     * Create a conflict response
     */
    public function conflict(
        string $message = 'Resource conflict',
        string $conflictingField = null
    ): JsonResponse {
        $details = [];
        if ($conflictingField) {
            $details['conflicting_field'] = $conflictingField;
        }

        return $this->error(
            ApiErrorCode::RESOURCE_CONFLICT,
            $message,
            $details
        );
    }

    /**
     * Create a rate limit response
     */
    public function rateLimitExceeded(
        int $limit,
        int $remaining,
        int $resetTime,
        int $retryAfter
    ): JsonResponse {
        $details = [
            'rate_limit' => [
                'limit' => $limit,
                'remaining' => $remaining,
                'reset_time' => $resetTime,
                'retry_after' => $retryAfter,
            ],
        ];

        return $this->error(
            ApiErrorCode::RATE_LIMIT_EXCEEDED,
            'Too many requests. Please try again later.',
            $details
        );
    }

    /**
     * Create a server error response
     */
    public function serverError(
        string $message = 'Internal server error',
        string $incidentId = null
    ): JsonResponse {
        $meta = [];
        if ($incidentId) {
            $meta['incident_id'] = $incidentId;
        }

        return $this->error(
            ApiErrorCode::INTERNAL_SERVER_ERROR,
            $message,
            [],
            $meta
        );
    }

    /**
     * Create a service unavailable response
     */
    public function serviceUnavailable(
        string $message = 'Service temporarily unavailable',
        string $estimatedRecovery = null
    ): JsonResponse {
        $meta = [];
        if ($estimatedRecovery) {
            $meta['estimated_recovery'] = $estimatedRecovery;
        }

        return $this->error(
            ApiErrorCode::SERVICE_UNAVAILABLE,
            $message,
            [],
            $meta
        );
    }

    /**
     * Create a business logic error response
     */
    public function businessError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        // Ensure it's a business error
        if ($errorCode->getErrorType() !== 'business_error') {
            throw new \InvalidArgumentException('Error code must be a business error type');
        }

        return $this->error($errorCode, $message, $details);
    }

    /**
     * Create a tenant error response
     */
    public function tenantError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        // Ensure it's a tenant error
        if ($errorCode->getErrorType() !== 'tenant_error') {
            throw new \InvalidArgumentException('Error code must be a tenant error type');
        }

        return $this->error($errorCode, $message, $details);
    }

    /**
     * Create a file error response
     */
    public function fileError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        // Ensure it's a file error
        if ($errorCode->getErrorType() !== 'file_error') {
            throw new \InvalidArgumentException('Error code must be a file error type');
        }

        return $this->error($errorCode, $message, $details);
    }

    /**
     * Create a paginated response
     */
    public function paginated(
        $data,
        int $currentPage,
        int $perPage,
        int $totalItems,
        string $message = 'Data retrieved successfully',
        array $filters = []
    ): JsonResponse {
        $totalPages = ceil($totalItems / $perPage);
        
        $pagination = [
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'has_next' => $currentPage < $totalPages,
            'has_previous' => $currentPage > 1,
            'next_url' => $currentPage < $totalPages ? $this->buildPageUrl($currentPage + 1) : null,
            'previous_url' => $currentPage > 1 ? $this->buildPageUrl($currentPage - 1) : null,
        ];

        $meta = [
            'pagination' => $pagination,
        ];

        if (!empty($filters)) {
            $meta['filters_applied'] = $filters;
        }

        return $this->success($data, $message, 200, $meta);
    }

    /**
     * Handle exceptions and convert to appropriate error responses
     */
    public function handleException(\Throwable $exception): JsonResponse
    {
        // Log the exception
        Log::error('API Exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Handle specific exception types
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return $this->validationError($exception->errors());
        }

        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('The requested resource was not found');
        }

        if ($exception instanceof \Illuminate\Auth\AuthenticationException) {
            return $this->unauthorized('Authentication required');
        }

        if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return $this->forbidden('Insufficient permissions');
        }

        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
            return $this->notFound('The requested endpoint was not found');
        }

        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException) {
            return $this->error(
                ApiErrorCode::OPERATION_NOT_ALLOWED,
                'The HTTP method is not allowed for this endpoint'
            );
        }

        if ($exception instanceof \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException) {
            return $this->rateLimitExceeded(1000, 0, time() + 3600, 3600);
        }

        // Default to server error
        return $this->serverError(
            'An unexpected error occurred',
            'INC-' . now()->format('Y-m-d') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT)
        );
    }

    /**
     * Build pagination URL
     */
    private function buildPageUrl(int $page): string
    {
        $query = $this->request->query();
        $query['page'] = $page;
        
        return $this->request->url() . '?' . http_build_query($query);
    }

    /**
     * Get request ID from request or generate new one
     */
    private function getRequestId(): string
    {
        return $this->request->header('X-Request-ID', uniqid('req_', true));
    }

    /**
     * Calculate execution time
     */
    private function getExecutionTime(): int
    {
        return (int) ((microtime(true) - $this->startTime) * 1000);
    }
}
