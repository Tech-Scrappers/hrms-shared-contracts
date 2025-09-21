<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Exception;

class ApiKeyService
{
    private const CACHE_PREFIX = 'api_key_';
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct()
    {
        // Service can be used across all microservices
    }

    /**
     * Validate API key and return key data
     *
     * @param string $apiKey
     * @return array|null
     */
    public function validateApiKey(string $apiKey): ?array
    {
        try {
            // Try to get from cache first
            $cacheKey = self::CACHE_PREFIX . hash('sha256', $apiKey);
            $cachedData = Cache::get($cacheKey);
            
            if ($cachedData) {
                return $cachedData;
            }

            // Query central database for API key (hybrid architecture)
            $apiKeys = DB::connection('pgsql')
                ->table('api_keys')
                ->where('is_active', true)
                ->get();

            // Check each API key using appropriate verification method
            foreach ($apiKeys as $apiKeyData) {
                $isValid = false;
                
                // Check if it's a bcrypt hash (starts with $2y$)
                if (str_starts_with($apiKeyData->key_hash, '$2y$')) {
                    try {
                        $isValid = \Illuminate\Support\Facades\Hash::check($apiKey, $apiKeyData->key_hash);
                    } catch (\Exception $e) {
                        // If bcrypt check fails, continue to next key
                        continue;
                    }
                } else {
                    // Assume it's SHA256 hash
                    $isValid = hash('sha256', $apiKey) === $apiKeyData->key_hash;
                }
                
                if ($isValid) {
                    $result = [
                        'id' => $apiKeyData->id,
                        'tenant_id' => $apiKeyData->tenant_id,
                        'name' => $apiKeyData->name,
                        'permissions' => json_decode($apiKeyData->permissions, true) ?? [],
                        'expires_at' => $apiKeyData->expires_at,
                        'is_active' => $apiKeyData->is_active,
                        'last_used_at' => $apiKeyData->last_used_at,
                        'created_at' => $apiKeyData->created_at,
                        'updated_at' => $apiKeyData->updated_at,
                    ];

                    // Cache the result
                    Cache::put($cacheKey, $result, self::CACHE_TTL);

                    return $result;
                }
            }

            // No API key found
            return null;

        } catch (Exception $e) {
            Log::error('API key validation error', [
                'error' => $e->getMessage(),
                'api_key_prefix' => substr($apiKey, 0, 10) . '...',
            ]);
            
            return null;
        }
    }

    /**
     * Update last used timestamp for API key
     *
     * @param string $apiKeyId
     * @return bool
     */
    public function updateLastUsed(string $apiKeyId): bool
    {
        try {
            $updated = DB::connection('pgsql')
                ->table('api_keys')
                ->where('id', $apiKeyId)
                ->update([
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated) {
                // Clear cache for this API key
                $this->clearApiKeyCache($apiKeyId);
            }

            return $updated > 0;

        } catch (Exception $e) {
            Log::error('Failed to update API key last used timestamp', [
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Check if API key has specific permission
     *
     * @param string $apiKey
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $apiKey, string $permission): bool
    {
        $apiKeyData = $this->validateApiKey($apiKey);
        
        if (!$apiKeyData) {
            return false;
        }

        $permissions = $apiKeyData['permissions'] ?? [];

        // Check for wildcard permission
        if (in_array('*', $permissions)) {
            return true;
        }

        // Check for specific permission
        return in_array($permission, $permissions);
    }

    /**
     * Get API key permissions
     *
     * @param string $apiKey
     * @return array
     */
    public function getPermissions(string $apiKey): array
    {
        $apiKeyData = $this->validateApiKey($apiKey);
        
        if (!$apiKeyData) {
            return [];
        }

        return $apiKeyData['permissions'] ?? [];
    }

    /**
     * Revoke API key
     *
     * @param string $apiKeyId
     * @return bool
     */
    public function revokeApiKey(string $apiKeyId): bool
    {
        try {
            $updated = DB::connection('pgsql')
                ->table('api_keys')
                ->where('id', $apiKeyId)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            if ($updated) {
                // Clear cache for this API key
                $this->clearApiKeyCache($apiKeyId);
                
                Log::info('API key revoked', [
                    'api_key_id' => $apiKeyId,
                ]);
            }

            return $updated > 0;

        } catch (Exception $e) {
            Log::error('Failed to revoke API key', [
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Get API key statistics
     *
     * @param string $tenantId
     * @return array
     */
    public function getApiKeyStats(string $tenantId): array
    {
        try {
            $stats = DB::connection('pgsql')
                ->table('api_keys')
                ->where('tenant_id', $tenantId)
                ->selectRaw('
                    COUNT(*) as total_keys,
                    COUNT(CASE WHEN is_active = true THEN 1 END) as active_keys,
                    COUNT(CASE WHEN is_active = false THEN 1 END) as inactive_keys,
                    COUNT(CASE WHEN expires_at IS NOT NULL AND expires_at > NOW() THEN 1 END) as expired_keys,
                    COUNT(CASE WHEN last_used_at IS NOT NULL THEN 1 END) as used_keys
                ')
                ->first();

            return [
                'total_keys' => (int) $stats->total_keys,
                'active_keys' => (int) $stats->active_keys,
                'inactive_keys' => (int) $stats->inactive_keys,
                'expired_keys' => (int) $stats->expired_keys,
                'used_keys' => (int) $stats->used_keys,
            ];

        } catch (Exception $e) {
            Log::error('Failed to get API key statistics', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'total_keys' => 0,
                'active_keys' => 0,
                'inactive_keys' => 0,
                'expired_keys' => 0,
                'used_keys' => 0,
            ];
        }
    }

    /**
     * Clean up expired API keys
     *
     * @return int Number of keys cleaned up
     */
    public function cleanupExpiredKeys(): int
    {
        try {
            $deleted = DB::connection('pgsql')
                ->table('api_keys')
                ->where('expires_at', '<', now())
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);

            if ($deleted > 0) {
                Log::info('Cleaned up expired API keys', [
                    'count' => $deleted,
                ]);
            }

            return $deleted;

        } catch (Exception $e) {
            Log::error('Failed to cleanup expired API keys', [
                'error' => $e->getMessage(),
            ]);
            
            return 0;
        }
    }

    /**
     * Clear API key cache
     *
     * @param string $apiKeyId
     * @return void
     */
    private function clearApiKeyCache(string $apiKeyId): void
    {
        try {
            // Get the API key to find its hash
            $apiKey = DB::connection('pgsql')
                ->table('api_keys')
                ->where('id', $apiKeyId)
                ->value('key_hash');

            if ($apiKey) {
                $cacheKey = self::CACHE_PREFIX . $apiKey;
                Cache::forget($cacheKey);
            }
        } catch (Exception $e) {
            Log::warning('Failed to clear API key cache', [
                'api_key_id' => $apiKeyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate a new API key
     *
     * @return string
     */
    public static function generateApiKey(): string
    {
        return 'ak_' . bin2hex(random_bytes(32));
    }

    /**
     * Hash API key for storage
     *
     * @param string $apiKey
     * @return string
     */
    public static function hashApiKey(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * Configure tenant database connection
     *
     * @param object $tenant
     * @return void
     */
    private function configureTenantConnection($tenant): void
    {
        $connectionName = "tenant_{$tenant->id}";
        
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => config('database.connections.pgsql.host'),
            'port' => config('database.connections.pgsql.port'),
            'database' => $tenant->database_name,
            'username' => config('database.connections.pgsql.username'),
            'password' => config('database.connections.pgsql.password'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }
}
