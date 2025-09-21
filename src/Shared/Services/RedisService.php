<?php

namespace Shared\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class RedisService
{
    private const TENANT_PREFIX = 'tenant:';

    private const SESSION_PREFIX = 'session:';

    private const CACHE_PREFIX = 'cache:';

    private const QUEUE_PREFIX = 'queue:';

    private const BROADCAST_PREFIX = 'broadcast:';

    private const RATE_LIMIT_PREFIX = 'rate_limit:';

    /**
     * Get tenant-specific Redis key
     */
    public function getTenantKey(string $tenantId, string $key, string $type = 'cache'): string
    {
        $prefix = match ($type) {
            'session' => self::SESSION_PREFIX,
            'cache' => self::CACHE_PREFIX,
            'queue' => self::QUEUE_PREFIX,
            'broadcast' => self::BROADCAST_PREFIX,
            'rate_limit' => self::RATE_LIMIT_PREFIX,
            default => self::CACHE_PREFIX
        };

        return $prefix.self::TENANT_PREFIX.$tenantId.':'.$key;
    }

    /**
     * Set cache with tenant isolation
     *
     * @param  mixed  $value
     */
    public function setCache(string $tenantId, string $key, $value, int $ttl = 3600): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $key, 'cache');
            $serializedValue = is_string($value) ? $value : serialize($value);

            return Redis::setex($redisKey, $ttl, $serializedValue);
        } catch (Exception $e) {
            Log::error('Redis cache set failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get cache with tenant isolation
     *
     * @param  mixed  $default
     * @return mixed
     */
    public function getCache(string $tenantId, string $key, $default = null)
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $key, 'cache');
            $value = Redis::get($redisKey);

            if ($value === null) {
                return $default;
            }

            // Try to unserialize, fallback to raw value
            $unserialized = @unserialize($value);

            return $unserialized !== false ? $unserialized : $value;
        } catch (Exception $e) {
            Log::error('Redis cache get failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    /**
     * Delete cache with tenant isolation
     */
    public function deleteCache(string $tenantId, string $key): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $key, 'cache');

            return Redis::del($redisKey) > 0;
        } catch (Exception $e) {
            Log::error('Redis cache delete failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Clear all cache for a tenant
     */
    public function clearTenantCache(string $tenantId): bool
    {
        try {
            $pattern = $this->getTenantKey($tenantId, '*', 'cache');
            $keys = Redis::keys($pattern);

            if (! empty($keys)) {
                return Redis::del($keys) > 0;
            }

            return true;
        } catch (Exception $e) {
            Log::error('Redis clear tenant cache failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Set session with tenant isolation
     */
    public function setSession(string $tenantId, string $sessionId, array $data, int $ttl = 7200): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $sessionId, 'session');

            return Redis::setex($redisKey, $ttl, json_encode($data));
        } catch (Exception $e) {
            Log::error('Redis session set failed', [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get session with tenant isolation
     */
    public function getSession(string $tenantId, string $sessionId): ?array
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $sessionId, 'session');
            $value = Redis::get($redisKey);

            if ($value === null) {
                return null;
            }

            return json_decode($value, true);
        } catch (Exception $e) {
            Log::error('Redis session get failed', [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Delete session with tenant isolation
     */
    public function deleteSession(string $tenantId, string $sessionId): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $sessionId, 'session');

            return Redis::del($redisKey) > 0;
        } catch (Exception $e) {
            Log::error('Redis session delete failed', [
                'tenant_id' => $tenantId,
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Push to queue with tenant isolation
     *
     * @param  mixed  $data
     */
    public function pushToQueue(string $tenantId, string $queue, $data): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $queue, 'queue');
            $serializedData = is_string($data) ? $data : json_encode($data);

            return Redis::lpush($redisKey, $serializedData) > 0;
        } catch (Exception $e) {
            Log::error('Redis queue push failed', [
                'tenant_id' => $tenantId,
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pop from queue with tenant isolation
     *
     * @return mixed
     */
    public function popFromQueue(string $tenantId, string $queue)
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $queue, 'queue');
            $value = Redis::rpop($redisKey);

            if ($value === null) {
                return;
            }

            // Try to decode JSON, fallback to raw value
            $decoded = json_decode($value, true);

            return $decoded !== null ? $decoded : $value;
        } catch (Exception $e) {
            Log::error('Redis queue pop failed', [
                'tenant_id' => $tenantId,
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return;
        }
    }

    /**
     * Publish broadcast with tenant isolation
     *
     * @param  mixed  $data
     */
    public function publishBroadcast(string $tenantId, string $channel, $data): bool
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $channel, 'broadcast');
            $serializedData = is_string($data) ? $data : json_encode($data);

            return Redis::publish($redisKey, $serializedData) > 0;
        } catch (Exception $e) {
            Log::error('Redis broadcast publish failed', [
                'tenant_id' => $tenantId,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Rate limiting with tenant isolation
     */
    public function rateLimit(string $tenantId, string $key, int $limit, int $window = 60): array
    {
        try {
            $redisKey = $this->getTenantKey($tenantId, $key, 'rate_limit');
            $current = Redis::incr($redisKey);

            if ($current === 1) {
                Redis::expire($redisKey, $window);
            }

            $remaining = max(0, $limit - $current);
            $resetTime = Redis::ttl($redisKey);

            return [
                'limit' => $limit,
                'remaining' => $remaining,
                'reset' => time() + $resetTime,
                'allowed' => $current <= $limit,
            ];
        } catch (Exception $e) {
            Log::error('Redis rate limit failed', [
                'tenant_id' => $tenantId,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return [
                'limit' => $limit,
                'remaining' => $limit,
                'reset' => time() + $window,
                'allowed' => true,
            ];
        }
    }

    /**
     * Get Redis statistics
     */
    public function getStats(): array
    {
        try {
            $info = Redis::info();

            return [
                'connected_clients' => $info['connected_clients'] ?? 0,
                'used_memory' => $info['used_memory_human'] ?? '0B',
                'keyspace_hits' => $info['keyspace_hits'] ?? 0,
                'keyspace_misses' => $info['keyspace_misses'] ?? 0,
                'total_commands_processed' => $info['total_commands_processed'] ?? 0,
                'instantaneous_ops_per_sec' => $info['instantaneous_ops_per_sec'] ?? 0,
            ];
        } catch (Exception $e) {
            Log::error('Redis stats failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Health check
     */
    public function isHealthy(): bool
    {
        try {
            return Redis::ping() === 'PONG';
        } catch (Exception $e) {
            Log::error('Redis health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
