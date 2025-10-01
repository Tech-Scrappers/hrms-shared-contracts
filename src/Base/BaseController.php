<?php

namespace Shared\Base;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Shared\Contracts\TenantAwareInterface;
use Shared\Enums\ApiErrorCode;

abstract class BaseController implements TenantAwareInterface
{
    use \Shared\Traits\StandardizedResponseTrait;
    use \Shared\Traits\TenantAwareTrait;

    /**
     * Return a success response (backward compatibility)
     * @deprecated Use success() method from StandardizedResponseTrait instead
     */
    protected function successResponse($data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return $this->success($data, $message, $statusCode);
    }

    /**
     * Return an error response (backward compatibility)
     * @deprecated Use error() method from StandardizedResponseTrait instead
     */
    protected function errorResponse(string $message = 'Error', int $statusCode = 400, ?string $errorCode = null, string $errorType = 'client_error', array $details = []): JsonResponse
    {
        // Map to appropriate ApiErrorCode
        $apiErrorCode = $this->mapToApiErrorCode($statusCode, $errorCode);
        return $this->error($apiErrorCode, $message, $details);
    }

    /**
     * Return a validation error response (backward compatibility)
     * @deprecated Use validationError() method from StandardizedResponseTrait instead
     */
    protected function validationErrorResponse(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->validationError($errors, $message);
    }

    /**
     * Return a not found response (backward compatibility)
     * @deprecated Use notFound() method from StandardizedResponseTrait instead
     */
    protected function notFoundErrorResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->notFound($message);
    }

    /**
     * Return an unauthorized response (backward compatibility)
     * @deprecated Use unauthorized() method from StandardizedResponseTrait instead
     */
    protected function authenticationErrorResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->unauthorized($message);
    }

    /**
     * Return a forbidden response (backward compatibility)
     * @deprecated Use forbidden() method from StandardizedResponseTrait instead
     */
    protected function authorizationErrorResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->forbidden($message);
    }

    /**
     * Return a server error response (backward compatibility)
     * @deprecated Use serverError() method from StandardizedResponseTrait instead
     */
    protected function serverErrorResponse(string $message = 'Internal server error'): JsonResponse
    {
        return $this->serverError($message);
    }

    /**
     * Map HTTP status code and error code to ApiErrorCode enum
     */
    private function mapToApiErrorCode(int $statusCode, ?string $errorCode): ApiErrorCode
    {
        if ($errorCode && defined("Shared\Enums\ApiErrorCode::$errorCode")) {
            return ApiErrorCode::from($errorCode);
        }

        return match ($statusCode) {
            400 => ApiErrorCode::BAD_REQUEST,
            401 => ApiErrorCode::AUTHENTICATION_REQUIRED,
            403 => ApiErrorCode::INSUFFICIENT_PERMISSIONS,
            404 => ApiErrorCode::RESOURCE_NOT_FOUND,
            409 => ApiErrorCode::RESOURCE_CONFLICT,
            422 => ApiErrorCode::VALIDATION_FAILED,
            429 => ApiErrorCode::RATE_LIMIT_EXCEEDED,
            500 => ApiErrorCode::INTERNAL_SERVER_ERROR,
            503 => ApiErrorCode::SERVICE_UNAVAILABLE,
            default => ApiErrorCode::INTERNAL_SERVER_ERROR,
        };
    }
}
