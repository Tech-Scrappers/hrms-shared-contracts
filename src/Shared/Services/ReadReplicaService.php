<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

/**
 * Read Replica Service
 * 
 * Manages read replica connections for database load balancing
 * and performance optimization in microservices architecture.
 */
class ReadReplicaService
{
    protected array $readReplicas = [];
    protected int $currentReplicaIndex = 0;
    protected string $writeConnection;
    protected string $readConnectionPrefix;

    public function __construct()
    {
        $this->writeConnection = config('database.default', 'pgsql');
        $this->readConnectionPrefix = 'read_replica_';
        $this->initializeReadReplicas();
    }

    /**
     * Initialize read replica connections
     */
    protected function initializeReadReplicas(): void
    {
        $replicas = config('database.read_replicas', []);
        
        foreach ($replicas as $index => $replica) {
            $connectionName = $this->readConnectionPrefix . $index;
            
            // Configure read replica connection
            Config::set("database.connections.{$connectionName}", [
                'driver' => $replica['driver'] ?? 'pgsql',
                'host' => $replica['host'],
                'port' => $replica['port'] ?? 5432,
                'database' => $replica['database'],
                'username' => $replica['username'],
                'password' => $replica['password'],
                'charset' => $replica['charset'] ?? 'utf8',
                'prefix' => $replica['prefix'] ?? '',
                'prefix_indexes' => $replica['prefix_indexes'] ?? true,
                'strict' => $replica['strict'] ?? true,
                'engine' => $replica['engine'] ?? null,
                'options' => $replica['options'] ?? [],
                'read' => [
                    'host' => $replica['read_host'] ?? $replica['host'],
                    'port' => $replica['read_port'] ?? $replica['port'] ?? 5432,
                ],
                'write' => [
                    'host' => $replica['write_host'] ?? $replica['host'],
                    'port' => $replica['write_port'] ?? $replica['port'] ?? 5432,
                ],
            ]);

            $this->readReplicas[] = [
                'name' => $connectionName,
                'weight' => $replica['weight'] ?? 1,
                'healthy' => true,
                'last_used' => null,
                'response_time' => 0,
            ];
        }

        Log::info('Read replica service initialized', [
            'replicas_count' => count($this->readReplicas),
            'write_connection' => $this->writeConnection,
        ]);
    }

    /**
     * Get a read replica connection for read operations
     */
    public function getReadConnection(): string
    {
        if (empty($this->readReplicas)) {
            return $this->writeConnection;
        }

        $healthyReplicas = array_filter($this->readReplicas, fn($replica) => $replica['healthy']);
        
        if (empty($healthyReplicas)) {
            Log::warning('No healthy read replicas available, using write connection');
            return $this->writeConnection;
        }

        // Use round-robin with weight consideration
        $selectedReplica = $this->selectReplica($healthyReplicas);
        
        // Update last used timestamp
        $this->readReplicas[array_search($selectedReplica, $this->readReplicas)]['last_used'] = now();
        
        Log::debug('Selected read replica', [
            'replica' => $selectedReplica['name'],
            'weight' => $selectedReplica['weight'],
        ]);

        return $selectedReplica['name'];
    }

    /**
     * Get write connection for write operations
     */
    public function getWriteConnection(): string
    {
        return $this->writeConnection;
    }

    /**
     * Execute a read operation using read replica
     */
    public function executeRead(callable $operation, ?string $connection = null)
    {
        $connection = $connection ?? $this->getReadConnection();
        
        $startTime = microtime(true);
        
        try {
            $result = DB::connection($connection)->transaction(function () use ($operation) {
                return $operation();
            });
            
            $this->recordReplicaResponseTime($connection, microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleReplicaError($connection, $e);
            throw $e;
        }
    }

    /**
     * Execute a write operation using write connection
     */
    public function executeWrite(callable $operation, ?string $connection = null)
    {
        $connection = $connection ?? $this->getWriteConnection();
        
        return DB::connection($connection)->transaction(function () use ($operation) {
            return $operation();
        });
    }

    /**
     * Execute a read operation with automatic retry on replica failure
     */
    public function executeReadWithRetry(callable $operation, int $maxRetries = 3): mixed
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->executeRead($operation);
                
            } catch (\Exception $e) {
                $lastException = $e;
                
                Log::warning('Read replica operation failed, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'error' => $e->getMessage(),
                ]);
                
                if ($attempt === $maxRetries) {
                    break;
                }
                
                // Wait before retry (exponential backoff)
                usleep(pow(2, $attempt) * 100000); // 0.1s, 0.2s, 0.4s
            }
        }
        
        // If all replicas failed, try write connection as fallback
        Log::warning('All read replicas failed, falling back to write connection');
        
        try {
            return $this->executeRead($operation, $this->writeConnection);
        } catch (\Exception $e) {
            Log::error('Read operation failed on all connections', [
                'error' => $e->getMessage(),
                'last_replica_error' => $lastException?->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Select a replica using weighted round-robin
     */
    protected function selectReplica(array $healthyReplicas): array
    {
        // Calculate total weight
        $totalWeight = array_sum(array_column($healthyReplicas, 'weight'));
        
        // Generate random number
        $random = mt_rand(1, $totalWeight);
        
        $currentWeight = 0;
        foreach ($healthyReplicas as $replica) {
            $currentWeight += $replica['weight'];
            if ($random <= $currentWeight) {
                return $replica;
            }
        }
        
        // Fallback to first replica
        return reset($healthyReplicas);
    }

    /**
     * Record replica response time
     */
    protected function recordReplicaResponseTime(string $connection, float $responseTime): void
    {
        foreach ($this->readReplicas as &$replica) {
            if ($replica['name'] === $connection) {
                $replica['response_time'] = $responseTime;
                break;
            }
        }
    }

    /**
     * Handle replica error
     */
    protected function handleReplicaError(string $connection, \Exception $exception): void
    {
        Log::error('Read replica error', [
            'connection' => $connection,
            'error' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
        ]);

        // Mark replica as unhealthy
        foreach ($this->readReplicas as &$replica) {
            if ($replica['name'] === $connection) {
                $replica['healthy'] = false;
                break;
            }
        }

        // Schedule health check
        $this->scheduleHealthCheck($connection);
    }

    /**
     * Schedule health check for a replica
     */
    protected function scheduleHealthCheck(string $connection): void
    {
        // In a real implementation, you might use a queue job
        // For now, we'll just log and mark as healthy after a delay
        Log::info('Scheduling health check for replica', [
            'connection' => $connection,
        ]);

        // Simulate health check after 30 seconds
        dispatch(function () use ($connection) {
            $this->performHealthCheck($connection);
        })->delay(now()->addSeconds(30));
    }

    /**
     * Perform health check on a replica
     */
    public function performHealthCheck(string $connection): bool
    {
        try {
            $startTime = microtime(true);
            
            DB::connection($connection)->select('SELECT 1');
            
            $responseTime = microtime(true) - $startTime;
            
            // Mark as healthy
            foreach ($this->readReplicas as &$replica) {
                if ($replica['name'] === $connection) {
                    $replica['healthy'] = true;
                    $replica['response_time'] = $responseTime;
                    break;
                }
            }
            
            Log::info('Replica health check passed', [
                'connection' => $connection,
                'response_time' => $responseTime,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Replica health check failed', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get replica statistics
     */
    public function getReplicaStats(): array
    {
        return [
            'total_replicas' => count($this->readReplicas),
            'healthy_replicas' => count(array_filter($this->readReplicas, fn($r) => $r['healthy'])),
            'unhealthy_replicas' => count(array_filter($this->readReplicas, fn($r) => !$r['healthy'])),
            'replicas' => $this->readReplicas,
            'write_connection' => $this->writeConnection,
        ];
    }

    /**
     * Force health check on all replicas
     */
    public function performAllHealthChecks(): array
    {
        $results = [];
        
        foreach ($this->readReplicas as $replica) {
            $results[$replica['name']] = $this->performHealthCheck($replica['name']);
        }
        
        return $results;
    }

    /**
     * Get the best performing replica
     */
    public function getBestReplica(): ?string
    {
        $healthyReplicas = array_filter($this->readReplicas, fn($r) => $r['healthy'] && $r['response_time'] > 0);
        
        if (empty($healthyReplicas)) {
            return null;
        }
        
        // Sort by response time (ascending)
        usort($healthyReplicas, fn($a, $b) => $a['response_time'] <=> $b['response_time']);
        
        return $healthyReplicas[0]['name'];
    }
}
