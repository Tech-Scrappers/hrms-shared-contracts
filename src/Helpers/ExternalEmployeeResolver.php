<?php

namespace Hrms\Shared\Helpers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Production-ready External Employee Resolver
 * 
 * This class provides robust employee resolution across microservices
 * with comprehensive error handling, logging, and fallback mechanisms.
 */
class ExternalEmployeeResolver
{
    private string $employeeServiceUrl;
    private int $timeout;
    private int $retryAttempts;

    public function __construct()
    {
        $this->employeeServiceUrl = rtrim(config('services.employee_service.url', 'http://employee-service:8002'), '/');
        $this->timeout = config('services.employee_service.timeout', 30);
        $this->retryAttempts = config('services.employee_service.retry_attempts', 3);
    }

    /**
     * Resolve internal employee ID from external identifiers
     * 
     * @param string $tenantId The tenant ID
     * @param array $params External identifiers (external_employee_id, external_user_id, etc.)
     * @param array $headers Authentication headers
     * @return string|null Internal employee ID or null if not found
     */
    public function resolveInternalEmployeeId(string $tenantId, array $params, array $headers = []): ?string
    {
        $context = [
            'tenant_id' => $tenantId,
            'params' => $params,
            'service' => 'external-employee-resolver'
        ];

        Log::info('Starting external employee resolution', $context);

        try {
            // Try to resolve by external_employee_id first
            if (!empty($params['external_employee_id'])) {
                $employeeId = $this->resolveByExternalEmployeeId($tenantId, $params, $headers);
                if ($employeeId) {
                    Log::info('Successfully resolved employee by external_employee_id', array_merge($context, [
                        'external_employee_id' => $params['external_employee_id'],
                        'internal_employee_id' => $employeeId
                    ]));
                    return $employeeId;
                }
            }

            // Try to resolve by external_user_id as fallback
            if (!empty($params['external_user_id'])) {
                $employeeId = $this->resolveByExternalUserId($tenantId, $params, $headers);
                if ($employeeId) {
                    Log::info('Successfully resolved employee by external_user_id', array_merge($context, [
                        'external_user_id' => $params['external_user_id'],
                        'internal_employee_id' => $employeeId
                    ]));
                    return $employeeId;
                }
            }

            Log::warning('Could not resolve employee from any external identifier', $context);
            return null;

        } catch (\Throwable $e) {
            Log::error('External employee resolution failed with exception', array_merge($context, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]));
            return null;
        }
    }

    /**
     * Resolve employee by external employee ID
     */
    private function resolveByExternalEmployeeId(string $tenantId, array $params, array $headers): ?string
    {
        $query = $this->buildQueryParams($params);
        $url = $this->employeeServiceUrl . '/api/external/v1/employees/' . urlencode((string) $params['external_employee_id']);

        return $this->makeRequest($url, $query, $headers, [
            'tenant_id' => $tenantId,
            'external_employee_id' => $params['external_employee_id']
        ]);
    }

    /**
     * Resolve employee by external user ID
     */
    private function resolveByExternalUserId(string $tenantId, array $params, array $headers): ?string
    {
        $query = $this->buildQueryParams($params);
        $url = $this->employeeServiceUrl . '/api/external/v1/employees/external-user/' . urlencode((string) $params['external_user_id']);

        return $this->makeRequest($url, $query, $headers, [
            'tenant_id' => $tenantId,
            'external_user_id' => $params['external_user_id']
        ]);
    }

    /**
     * Build query parameters for the request
     */
    private function buildQueryParams(array $params): array
    {
        $query = [];
        if (!empty($params['external_tenant_id'])) {
            $query['external_tenant_id'] = (string) $params['external_tenant_id'];
        }
        if (!empty($params['external_branch_id'])) {
            $query['external_branch_id'] = (string) $params['external_branch_id'];
        }
        return $query;
    }

    /**
     * Make HTTP request with retry logic and comprehensive error handling
     */
    private function makeRequest(string $url, array $query, array $headers, array $context): ?string
    {
        $client = Http::timeout($this->timeout)
            ->withHeaders(array_filter([
                'Accept' => 'application/json',
                'HRMS-Client-ID' => $context['tenant_id'],
                'HRMS-Client-Secret' => $headers['HRMS-Client-Secret'] ?? null,
                'Authorization' => $headers['Authorization'] ?? null,
            ]));

        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            $attempt++;
            
            try {
                Log::info('Making external employee resolution request', array_merge($context, [
                    'url' => $url,
                    'attempt' => $attempt,
                    'max_attempts' => $this->retryAttempts
                ]));

                $response = $client->get($url, $query);

                Log::info('External employee resolution response received', array_merge($context, [
                    'status_code' => $response->status(),
                    'attempt' => $attempt
                ]));

                if ($response->successful()) {
                    $employeeId = $response->json('data.id');
                    if ($employeeId) {
                        return $employeeId;
                    } else {
                        Log::warning('Employee resolution returned null employee ID', array_merge($context, [
                            'response_data' => $response->json(),
                            'attempt' => $attempt
                        ]));
                    }
                } else {
                    Log::warning('Employee resolution request failed', array_merge($context, [
                        'status_code' => $response->status(),
                        'response_body' => $response->body(),
                        'attempt' => $attempt
                    ]));

                    // Don't retry on client errors (4xx)
                    if ($response->status() >= 400 && $response->status() < 500) {
                        break;
                    }
                }

            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('Employee resolution request exception', array_merge($context, [
                    'exception' => $e->getMessage(),
                    'attempt' => $attempt,
                    'max_attempts' => $this->retryAttempts
                ]));

                // Don't retry on the last attempt
                if ($attempt < $this->retryAttempts) {
                    $delay = $this->calculateRetryDelay($attempt);
                    Log::info('Retrying employee resolution request', array_merge($context, [
                        'delay_ms' => $delay,
                        'next_attempt' => $attempt + 1
                    ]));
                    usleep($delay * 1000); // Convert to microseconds
                }
            }
        }

        if ($lastException) {
            Log::error('All employee resolution attempts failed', array_merge($context, [
                'final_exception' => $lastException->getMessage(),
                'total_attempts' => $attempt
            ]));
        }

        return null;
    }

    /**
     * Calculate exponential backoff delay for retries
     */
    private function calculateRetryDelay(int $attempt): int
    {
        // Exponential backoff: 100ms, 200ms, 400ms, etc.
        return min(100 * pow(2, $attempt - 1), 2000); // Max 2 seconds
    }

    /**
     * Validate external identifiers
     */
    public function validateExternalIdentifiers(array $params): array
    {
        $errors = [];

        if (empty($params['external_employee_id']) && empty($params['external_user_id'])) {
            $errors[] = 'Either external_employee_id or external_user_id must be provided';
        }

        if (!empty($params['external_employee_id']) && !is_string($params['external_employee_id'])) {
            $errors[] = 'external_employee_id must be a string';
        }

        if (!empty($params['external_user_id']) && !is_string($params['external_user_id'])) {
            $errors[] = 'external_user_id must be a string';
        }

        return $errors;
    }

    /**
     * Get resolution statistics for monitoring
     */
    public function getResolutionStats(): array
    {
        // This could be enhanced to track actual statistics
        return [
            'service_url' => $this->employeeServiceUrl,
            'timeout' => $this->timeout,
            'retry_attempts' => $this->retryAttempts,
            'status' => 'operational'
        ];
    }
}
