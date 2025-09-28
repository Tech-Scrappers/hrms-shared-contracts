<?php

namespace Shared\Base;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Shared\Contracts\TenantAwareInterface;

abstract class BaseController implements TenantAwareInterface
{
    use \Shared\Traits\EnterpriseApiResponseTrait;
    use \Shared\Traits\TenantAwareTrait;

    /**
     * Return a success response (backward compatibility)
     */
    protected function success($data = null, string $message = 'Success', int $statusCode = 200): JsonResponse
    {
        return $this->successResponse($data, $message, $statusCode);
    }

    /**
     * Return an error response (backward compatibility)
     */
    protected function error(string $message = 'Error', $errors = null, int $statusCode = 400): JsonResponse
    {
        return $this->errorResponse($message, $statusCode, null, 'client_error', $errors ?? []);
    }

    /**
     * Return a validation error response (backward compatibility)
     */
    protected function validationError($errors, string $message = 'Validation failed'): JsonResponse
    {
        return $this->validationErrorResponse($errors, $message);
    }

    /**
     * Return a not found response (backward compatibility)
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->notFoundErrorResponse($message);
    }

    /**
     * Return an unauthorized response (backward compatibility)
     */
    protected function unauthorized(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->authenticationErrorResponse($message);
    }

    /**
     * Return a forbidden response (backward compatibility)
     */
    protected function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return $this->authorizationErrorResponse($message);
    }

    /**
     * Return a server error response (backward compatibility)
     */
    protected function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return $this->serverErrorResponse($message);
    }
}
