<?php

namespace Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Enums\ApiErrorCode;
use Shared\Services\ApiResponseService;

/**
 * Standardized Response Trait
 * 
 * This trait provides a standardized way to create API responses
 * across all controllers in the HRMS microservices system.
 * 
 * All controllers should use this trait instead of direct response methods
 * to ensure consistency and maintainability.
 */
trait StandardizedResponseTrait
{
    private ?ApiResponseService $responseService = null;

    /**
     * Get the API response service instance
     */
    protected function getResponseService(): ApiResponseService
    {
        if ($this->responseService === null) {
            $this->responseService = new ApiResponseService(request());
        }

        return $this->responseService;
    }

    /**
     * Create a success response
     */
    protected function success(
        $data = null,
        string $message = 'Success',
        int $statusCode = 200,
        array $meta = []
    ): JsonResponse {
        return $this->getResponseService()->success($data, $message, $statusCode, $meta);
    }

    /**
     * Create a created response (201)
     */
    protected function created(
        $data = null,
        string $message = 'Resource created successfully',
        ?string $location = null
    ): JsonResponse {
        return $this->getResponseService()->created($data, $message, $location);
    }

    /**
     * Create a no content response (204)
     */
    protected function noContent(): JsonResponse
    {
        return $this->getResponseService()->noContent();
    }

    /**
     * Create an error response using error codes
     */
    protected function error(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = [],
        array $meta = []
    ): JsonResponse {
        return $this->getResponseService()->error($errorCode, $message, $details, $meta);
    }

    /**
     * Create a validation error response
     */
    protected function validationError(
        array $validationErrors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->getResponseService()->validationError($validationErrors, $message);
    }

    /**
     * Create a not found response
     */
    protected function notFound(
        string $message = 'Resource not found',
        string $resourceType = 'resource',
        string $resourceId = null
    ): JsonResponse {
        return $this->getResponseService()->notFound($message, $resourceType, $resourceId);
    }

    /**
     * Create an unauthorized response
     */
    protected function unauthorized(
        string $message = 'Authentication required'
    ): JsonResponse {
        return $this->getResponseService()->unauthorized($message);
    }

    /**
     * Create a forbidden response
     */
    protected function forbidden(
        string $message = 'Insufficient permissions',
        array $requiredPermissions = []
    ): JsonResponse {
        return $this->getResponseService()->forbidden($message, $requiredPermissions);
    }

    /**
     * Create a conflict response
     */
    protected function conflict(
        string $message = 'Resource conflict',
        string $conflictingField = null
    ): JsonResponse {
        return $this->getResponseService()->conflict($message, $conflictingField);
    }

    /**
     * Create a rate limit response
     */
    protected function rateLimitExceeded(
        int $limit,
        int $remaining,
        int $resetTime,
        int $retryAfter
    ): JsonResponse {
        return $this->getResponseService()->rateLimitExceeded($limit, $remaining, $resetTime, $retryAfter);
    }

    /**
     * Create a server error response
     */
    protected function serverError(
        string $message = 'Internal server error',
        string $incidentId = null
    ): JsonResponse {
        return $this->getResponseService()->serverError($message, $incidentId);
    }

    /**
     * Create a service unavailable response
     */
    protected function serviceUnavailable(
        string $message = 'Service temporarily unavailable',
        string $estimatedRecovery = null
    ): JsonResponse {
        return $this->getResponseService()->serviceUnavailable($message, $estimatedRecovery);
    }

    /**
     * Create a business logic error response
     */
    protected function businessError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        return $this->getResponseService()->businessError($errorCode, $message, $details);
    }

    /**
     * Create a tenant error response
     */
    protected function tenantError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        return $this->getResponseService()->tenantError($errorCode, $message, $details);
    }

    /**
     * Create a file error response
     */
    protected function fileError(
        ApiErrorCode $errorCode,
        string $message = null,
        array $details = []
    ): JsonResponse {
        return $this->getResponseService()->fileError($errorCode, $message, $details);
    }

    /**
     * Create a paginated response
     */
    protected function paginated(
        $data,
        int $currentPage,
        int $perPage,
        int $totalItems,
        string $message = 'Data retrieved successfully',
        array $filters = []
    ): JsonResponse {
        return $this->getResponseService()->paginated(
            $data,
            $currentPage,
            $perPage,
            $totalItems,
            $message,
            $filters
        );
    }

    /**
     * Handle exceptions and convert to appropriate error responses
     */
    protected function handleException(\Throwable $exception): JsonResponse
    {
        return $this->getResponseService()->handleException($exception);
    }

    // Convenience methods for common business errors

    /**
     * Create an attendance already recorded error
     */
    protected function attendanceAlreadyRecorded(string $message = null): JsonResponse
    {
        return $this->businessError(
            ApiErrorCode::ATTENDANCE_ALREADY_RECORDED,
            $message ?? 'Attendance has already been recorded for this period'
        );
    }

    /**
     * Create an attendance not found error
     */
    protected function attendanceNotFound(string $message = null): JsonResponse
    {
        return $this->businessError(
            ApiErrorCode::ATTENDANCE_NOT_FOUND,
            $message ?? 'No attendance record found for the specified criteria'
        );
    }

    /**
     * Create an insufficient leave balance error
     */
    protected function insufficientLeaveBalance(string $message = null): JsonResponse
    {
        return $this->businessError(
            ApiErrorCode::LEAVE_BALANCE_INSUFFICIENT,
            $message ?? 'Insufficient leave balance for this request'
        );
    }

    /**
     * Create a leave request conflict error
     */
    protected function leaveRequestConflict(string $message = null): JsonResponse
    {
        return $this->businessError(
            ApiErrorCode::LEAVE_REQUEST_CONFLICT,
            $message ?? 'This leave request conflicts with existing requests'
        );
    }

    /**
     * Create a tenant not found error
     */
    protected function tenantNotFound(string $message = null): JsonResponse
    {
        return $this->tenantError(
            ApiErrorCode::TENANT_NOT_FOUND,
            $message ?? 'The specified tenant could not be found'
        );
    }

    /**
     * Create an invalid tenant context error
     */
    protected function invalidTenantContext(string $message = null): JsonResponse
    {
        return $this->tenantError(
            ApiErrorCode::INVALID_TENANT_CONTEXT,
            $message ?? 'The tenant context is invalid or missing'
        );
    }

    /**
     * Create a file too large error
     */
    protected function fileTooLarge(string $message = null): JsonResponse
    {
        return $this->fileError(
            ApiErrorCode::FILE_TOO_LARGE,
            $message ?? 'The file size exceeds the maximum allowed limit'
        );
    }

    /**
     * Create an invalid file type error
     */
    protected function invalidFileType(string $message = null): JsonResponse
    {
        return $this->fileError(
            ApiErrorCode::INVALID_FILE_TYPE,
            $message ?? 'The file type is not supported'
        );
    }

    /**
     * Create a virus detected error
     */
    protected function virusDetected(string $message = null): JsonResponse
    {
        return $this->fileError(
            ApiErrorCode::VIRUS_DETECTED,
            $message ?? 'A virus was detected in the uploaded file'
        );
    }
}
