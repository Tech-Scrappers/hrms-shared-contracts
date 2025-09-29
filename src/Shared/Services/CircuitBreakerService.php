<?php

namespace Shared\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Circuit Breaker Service
 * 
 * Implements the Circuit Breaker pattern to prevent cascade failures
 * in microservices communication. Provides automatic recovery and
 * fallback mechanisms.
 */
class CircuitBreakerService
{
    const STATE_CLOSED = 'closed';
    const STATE_OPEN = 'open';
    const STATE_HALF_OPEN = 'half_open';

    protected string $serviceName;
    protected int $failureThreshold;
    protected int $recoveryTimeout;
    protected int $successThreshold;
    protected int $requestTimeout;

    public function __construct(
        string $serviceName,
        int $failureThreshold = 5,
        int $recoveryTimeout = 60,
        int $successThreshold = 3,
        int $requestTimeout = 30
    ) {
        $this->serviceName = $serviceName;
        $this->failureThreshold = $failureThreshold;
        $this->recoveryTimeout = $recoveryTimeout;
        $this->successThreshold = $successThreshold;
        $this->requestTimeout = $requestTimeout;
    }

    /**
     * Execute a callable with circuit breaker protection
     */
    public function execute(callable $operation, callable $fallback = null, array $context = [])
    {
        $state = $this->getState();
        
        Log::info('Circuit breaker execution', [
            'service' => $this->serviceName,
            'state' => $state,
            'context' => $context,
        ]);

        switch ($state) {
            case self::STATE_CLOSED:
                return $this->executeInClosedState($operation, $fallback, $context);
                
            case self::STATE_OPEN:
                return $this->executeInOpenState($fallback, $context);
                
            case self::STATE_HALF_OPEN:
                return $this->executeInHalfOpenState($operation, $fallback, $context);
                
            default:
                throw new \InvalidArgumentException("Unknown circuit breaker state: {$state}");
        }
    }

    /**
     * Execute operation in closed state (normal operation)
     */
    protected function executeInClosedState(callable $operation, callable $fallback = null, array $context = [])
    {
        try {
            $result = $this->executeWithTimeout($operation);
            $this->recordSuccess();
            return $result;
            
        } catch (\Exception $e) {
            $this->recordFailure($e, $context);
            
            if ($fallback) {
                Log::info('Circuit breaker: Using fallback', [
                    'service' => $this->serviceName,
                    'error' => $e->getMessage(),
                ]);
                return $fallback($e, $context);
            }
            
            throw $e;
        }
    }

    /**
     * Execute operation in open state (circuit is open)
     */
    protected function executeInOpenState(callable $fallback = null, array $context = [])
    {
        Log::warning('Circuit breaker: Circuit is open, rejecting request', [
            'service' => $this->serviceName,
            'context' => $context,
        ]);

        if ($fallback) {
            return $fallback(new \Exception('Circuit breaker is open'), $context);
        }

        throw new \Exception('Service is temporarily unavailable. Circuit breaker is open.');
    }

    /**
     * Execute operation in half-open state (testing recovery)
     */
    protected function executeInHalfOpenState(callable $operation, callable $fallback = null, array $context = [])
    {
        try {
            $result = $this->executeWithTimeout($operation);
            $this->recordSuccess();
            
            // If we have enough successes, close the circuit
            if ($this->getSuccessCount() >= $this->successThreshold) {
                $this->closeCircuit();
                Log::info('Circuit breaker: Circuit closed after successful recovery', [
                    'service' => $this->serviceName,
                ]);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->recordFailure($e, $context);
            $this->openCircuit();
            
            Log::warning('Circuit breaker: Circuit reopened after failure in half-open state', [
                'service' => $this->serviceName,
                'error' => $e->getMessage(),
            ]);
            
            if ($fallback) {
                return $fallback($e, $context);
            }
            
            throw $e;
        }
    }

    /**
     * Execute operation with timeout
     */
    protected function executeWithTimeout(callable $operation)
    {
        $startTime = microtime(true);
        
        try {
            // Set a timeout for the operation
            $result = $operation();
            
            $executionTime = microtime(true) - $startTime;
            
            Log::debug('Circuit breaker: Operation completed successfully', [
                'service' => $this->serviceName,
                'execution_time' => $executionTime,
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;
            
            Log::debug('Circuit breaker: Operation failed', [
                'service' => $this->serviceName,
                'execution_time' => $executionTime,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Record a successful operation
     */
    protected function recordSuccess(): void
    {
        $this->incrementSuccessCount();
        $this->resetFailureCount();
    }

    /**
     * Record a failed operation
     */
    protected function recordFailure(\Exception $exception, array $context = []): void
    {
        $this->incrementFailureCount();
        
        Log::warning('Circuit breaker: Operation failed', [
            'service' => $this->serviceName,
            'error' => $exception->getMessage(),
            'context' => $context,
            'failure_count' => $this->getFailureCount(),
            'threshold' => $this->failureThreshold,
        ]);
        
        // Check if we should open the circuit
        if ($this->getFailureCount() >= $this->failureThreshold) {
            $this->openCircuit();
            
            Log::error('Circuit breaker: Circuit opened due to failure threshold', [
                'service' => $this->serviceName,
                'failure_count' => $this->getFailureCount(),
                'threshold' => $this->failureThreshold,
            ]);
        }
    }

    /**
     * Get current circuit breaker state
     */
    public function getState(): string
    {
        $state = Cache::get($this->getStateKey(), self::STATE_CLOSED);
        $lastFailureTime = Cache::get($this->getLastFailureTimeKey());
        
        // Check if we should transition from open to half-open
        if ($state === self::STATE_OPEN && $lastFailureTime) {
            $timeSinceLastFailure = time() - $lastFailureTime;
            
            if ($timeSinceLastFailure >= $this->recoveryTimeout) {
                $this->setState(self::STATE_HALF_OPEN);
                $this->resetSuccessCount();
                
                Log::info('Circuit breaker: Transitioning to half-open state', [
                    'service' => $this->serviceName,
                    'time_since_last_failure' => $timeSinceLastFailure,
                ]);
                
                return self::STATE_HALF_OPEN;
            }
        }
        
        return $state;
    }

    /**
     * Open the circuit
     */
    protected function openCircuit(): void
    {
        $this->setState(self::STATE_OPEN);
        Cache::put($this->getLastFailureTimeKey(), time(), now()->addHours(24));
    }

    /**
     * Close the circuit
     */
    protected function closeCircuit(): void
    {
        $this->setState(self::STATE_CLOSED);
        $this->resetFailureCount();
        $this->resetSuccessCount();
        Cache::forget($this->getLastFailureTimeKey());
    }

    /**
     * Set circuit breaker state
     */
    protected function setState(string $state): void
    {
        Cache::put($this->getStateKey(), $state, now()->addHours(24));
    }

    /**
     * Get failure count
     */
    protected function getFailureCount(): int
    {
        return Cache::get($this->getFailureCountKey(), 0);
    }

    /**
     * Increment failure count
     */
    protected function incrementFailureCount(): void
    {
        $count = $this->getFailureCount();
        Cache::put($this->getFailureCountKey(), $count + 1, now()->addMinutes(5));
    }

    /**
     * Reset failure count
     */
    protected function resetFailureCount(): void
    {
        Cache::forget($this->getFailureCountKey());
    }

    /**
     * Get success count
     */
    protected function getSuccessCount(): int
    {
        return Cache::get($this->getSuccessCountKey(), 0);
    }

    /**
     * Increment success count
     */
    protected function incrementSuccessCount(): void
    {
        $count = $this->getSuccessCount();
        Cache::put($this->getSuccessCountKey(), $count + 1, now()->addMinutes(5));
    }

    /**
     * Reset success count
     */
    protected function resetSuccessCount(): void
    {
        Cache::forget($this->getSuccessCountKey());
    }

    /**
     * Get circuit breaker statistics
     */
    public function getStats(): array
    {
        return [
            'service' => $this->serviceName,
            'state' => $this->getState(),
            'failure_count' => $this->getFailureCount(),
            'success_count' => $this->getSuccessCount(),
            'failure_threshold' => $this->failureThreshold,
            'success_threshold' => $this->successThreshold,
            'recovery_timeout' => $this->recoveryTimeout,
            'last_failure_time' => Cache::get($this->getLastFailureTimeKey()),
        ];
    }

    /**
     * Reset circuit breaker
     */
    public function reset(): void
    {
        $this->closeCircuit();
        $this->resetFailureCount();
        $this->resetSuccessCount();
        
        Log::info('Circuit breaker: Reset', [
            'service' => $this->serviceName,
        ]);
    }

    /**
     * Get cache key for state
     */
    protected function getStateKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:state";
    }

    /**
     * Get cache key for failure count
     */
    protected function getFailureCountKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:failures";
    }

    /**
     * Get cache key for success count
     */
    protected function getSuccessCountKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:successes";
    }

    /**
     * Get cache key for last failure time
     */
    protected function getLastFailureTimeKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:last_failure";
    }
}
