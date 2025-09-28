<?php

namespace Shared\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Exception;
use Throwable;

trait ErrorHandlingTrait
{
    /**
     * Handle exceptions and return appropriate JSON response
     *
     * @param Throwable $e
     * @param string $context
     * @param array $additionalData
     * @return JsonResponse
     */
    protected function handleException(Throwable $e, string $context = 'Operation', array $additionalData = []): JsonResponse
    {
        $logData = array_merge([
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], $additionalData);

        // Handle different exception types
        if ($e instanceof ValidationException) {
            Log::warning("Validation failed in {$context}", $logData);
            return $this->validationErrorResponse($e->errors(), 'Validation failed');
        }

        if ($e instanceof QueryException) {
            Log::error("Database error in {$context}", $logData);
            return $this->databaseErrorResponse('Database operation failed');
        }

        if ($e instanceof HttpException) {
            Log::warning("HTTP error in {$context}", $logData);
            return $this->httpErrorResponse($e->getMessage(), $e->getStatusCode());
        }

        if ($e instanceof \InvalidArgumentException) {
            Log::warning("Invalid argument in {$context}", $logData);
            return $this->badRequestResponse($e->getMessage());
        }

        // Generic exception/throwable
        Log::error("Unexpected error in {$context}", $logData);
        return $this->serverErrorResponse('An unexpected error occurred');
    }

    /**
     * Return validation error response
     */
    protected function validationErrorResponse(array $errors, string $message = 'Validation failed'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'VALIDATION_ERROR',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Return database error response
     */
    protected function databaseErrorResponse(string $message = 'Database operation failed'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'DATABASE_ERROR',
        ], 500);
    }

    /**
     * Return HTTP error response
     */
    protected function httpErrorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'HTTP_ERROR',
        ], $statusCode);
    }

    /**
     * Return bad request response
     */
    protected function badRequestResponse(string $message = 'Bad request'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'BAD_REQUEST',
        ], 400);
    }

    /**
     * Return server error response
     */
    protected function serverErrorResponse(string $message = 'Internal server error'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'SERVER_ERROR',
        ], 500);
    }

    /**
     * Return unauthorized response
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
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
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'FORBIDDEN',
        ], 403);
    }

    /**
     * Return not found response
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'NOT_FOUND',
        ], 404);
    }

    /**
     * Log security event
     */
    protected function logSecurityEvent(string $eventType, array $context, array $additionalData = []): void
    {
        Log::warning("Security Event: {$eventType}", array_merge([
            'event_type' => $eventType,
            'timestamp' => now()->toISOString(),
            'context' => $context,
        ], $additionalData));
    }

    /**
     * Validate tenant context
     */
    protected function validateTenantContext(string $tenantId, array $user): void
    {
        if (empty($tenantId)) {
            throw new \InvalidArgumentException('Tenant context is required');
        }

        // Super admin can access any tenant
        if (isset($user['role']) && $user['role'] === 'super_admin') {
            return;
        }

        // For regular users, validate tenant access
        if (isset($user['tenant_id']) && $user['tenant_id'] !== $tenantId) {
            $this->logSecurityEvent('cross_tenant_access_attempt', [
                'user_id' => $user['id'] ?? 'N/A',
                'user_tenant_id' => $user['tenant_id'] ?? 'N/A',
                'requested_tenant_id' => $tenantId,
            ]);

            throw new \InvalidArgumentException('Access denied: Invalid tenant context');
        }
    }
}
