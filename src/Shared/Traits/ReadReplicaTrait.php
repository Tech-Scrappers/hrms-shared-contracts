<?php

namespace Shared\Traits;

use Shared\Services\ReadReplicaService;

/**
 * Read Replica Trait
 * 
 * Provides methods for services to easily use read replicas
 * for database operations.
 */
trait ReadReplicaTrait
{
    protected ?ReadReplicaService $readReplicaService = null;

    /**
     * Get the read replica service instance
     */
    protected function getReadReplicaService(): ReadReplicaService
    {
        if (!$this->readReplicaService) {
            $this->readReplicaService = app(ReadReplicaService::class);
        }

        return $this->readReplicaService;
    }

    /**
     * Execute a read operation using read replica
     */
    protected function executeRead(callable $operation, ?string $connection = null): mixed
    {
        return $this->getReadReplicaService()->executeRead($operation, $connection);
    }

    /**
     * Execute a write operation using write connection
     */
    protected function executeWrite(callable $operation, ?string $connection = null): mixed
    {
        return $this->getReadReplicaService()->executeWrite($operation, $connection);
    }

    /**
     * Execute a read operation with automatic retry
     */
    protected function executeReadWithRetry(callable $operation, int $maxRetries = 3): mixed
    {
        return $this->getReadReplicaService()->executeReadWithRetry($operation, $maxRetries);
    }

    /**
     * Get read connection name
     */
    protected function getReadConnection(): string
    {
        return $this->getReadReplicaService()->getReadConnection();
    }

    /**
     * Get write connection name
     */
    protected function getWriteConnection(): string
    {
        return $this->getReadReplicaService()->getWriteConnection();
    }

    /**
     * Get the best performing replica
     */
    protected function getBestReplica(): ?string
    {
        return $this->getReadReplicaService()->getBestReplica();
    }

    /**
     * Get replica statistics
     */
    protected function getReplicaStats(): array
    {
        return $this->getReadReplicaService()->getReplicaStats();
    }

    /**
     * Perform health check on all replicas
     */
    protected function performAllHealthChecks(): array
    {
        return $this->getReadReplicaService()->performAllHealthChecks();
    }
}
