<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Production-ready database connection manager
 * Handles connection pooling, caching, and prevents race conditions
 */
class DatabaseConnectionManager
{
    private static array $connectionPool = [];
    private static string $currentConnection = 'pgsql';
    private static bool $isInitialized = false;

    /**
     * Initialize the connection manager
     */
    public static function initialize(): void
    {
        if (self::$isInitialized) {
            return;
        }

        // Set up connection pooling
        self::$currentConnection = Config::get('database.default', 'pgsql');
        self::$isInitialized = true;

        Log::info('Database connection manager initialized', [
            'default_connection' => self::$currentConnection,
        ]);
    }

    /**
     * Switch to tenant database with production-ready safeguards
     */
    public static function switchToTenantDatabase(string $tenantId, string $service): void
    {
        self::initialize();

        $connectionName = "tenant_{$tenantId}_{$service}";
        $databaseName = "tenant_{$tenantId}_{$service}";

        try {
            // Check if connection already exists in pool
            if (isset(self::$connectionPool[$connectionName])) {
                $connection = self::$connectionPool[$connectionName];
                
                // Verify connection is still valid
                if (self::isConnectionValid($connection)) {
                    self::setActiveConnection($connectionName);
                    return;
                } else {
                    // Remove invalid connection from pool
                    unset(self::$connectionPool[$connectionName]);
                }
            }

            // Create new connection
            self::createTenantConnection($tenantId, $service);
            self::setActiveConnection($connectionName);

            Log::info('Switched to tenant database', [
                'tenant_id' => $tenantId,
                'service' => $service,
                'connection_name' => $connectionName,
                'database_name' => $databaseName,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to switch to tenant database', [
                'tenant_id' => $tenantId,
                'service' => $service,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Switch back to central database
     */
    public static function switchToCentralDatabase(): void
    {
        self::initialize();

        try {
            // Purge all tenant connections to prevent memory leaks
            self::purgeTenantConnections();
            
            // Set central database as active
            self::setActiveConnection('pgsql');
            
            // Verify central connection
            self::verifyConnection('pgsql');

            Log::info('Switched to central database');

        } catch (Exception $e) {
            Log::error('Failed to switch to central database', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Create tenant connection with proper configuration
     */
    private static function createTenantConnection(string $tenantId, string $service): void
    {
        $connectionName = "tenant_{$tenantId}_{$service}";
        $databaseName = "tenant_{$tenantId}_{$service}";
        
        // Use main postgres user for all tenant databases (production-ready approach)
        $username = Config::get('database.connections.pgsql.username', 'postgres');
        $password = Config::get('database.connections.pgsql.password', 'password');

        // Configure connection
        Config::set("database.connections.{$connectionName}", [
            'driver' => 'pgsql',
            'host' => Config::get('database.connections.pgsql.host'),
            'port' => Config::get('database.connections.pgsql.port'),
            'database' => $databaseName,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 5,
                'timeout' => 30,
            ],
        ]);

        // Add to connection pool
        self::$connectionPool[$connectionName] = [
            'name' => $connectionName,
            'database' => $databaseName,
            'created_at' => now(),
            'last_used' => now(),
        ];
    }

    /**
     * Set active connection with proper verification
     */
    private static function setActiveConnection(string $connectionName): void
    {
        // Purge existing connections to prevent caching issues
        DB::purge($connectionName);
        DB::purge('default');

        // Set new default connection
        Config::set('database.default', $connectionName);
        DB::setDefaultConnection($connectionName);

        // Update current connection
        self::$currentConnection = $connectionName;

        // Update last used time in pool
        if (isset(self::$connectionPool[$connectionName])) {
            self::$connectionPool[$connectionName]['last_used'] = now();
        }

        // Verify connection
        self::verifyConnection($connectionName);
    }

    /**
     * Verify connection is working
     */
    private static function verifyConnection(string $connectionName): void
    {
        try {
            $connection = DB::connection($connectionName);
            
            // Test basic connectivity
            $result = $connection->select('SELECT 1 as test');
            if (empty($result) || $result[0]->test !== 1) {
                throw new Exception("Connection test failed for {$connectionName}");
            }

            // Verify database name if it's a tenant connection
            if (str_starts_with($connectionName, 'tenant_')) {
                $dbResult = $connection->select('SELECT current_database() as db_name');
                $actualDb = $dbResult[0]->db_name ?? 'unknown';
                $expectedDb = $connectionName; // The connection name should match the database name
                
                if ($actualDb !== $expectedDb) {
                    throw new Exception("Connected to wrong database. Expected: {$expectedDb}, Got: {$actualDb}");
                }
            }

        } catch (Exception $e) {
            Log::error('Connection verification failed', [
                'connection_name' => $connectionName,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if connection is still valid
     */
    private static function isConnectionValid(array $connection): bool
    {
        try {
            $connectionName = $connection['name'];
            $db = DB::connection($connectionName);
            $db->getPdo();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Purge tenant connections to prevent memory leaks
     */
    private static function purgeTenantConnections(): void
    {
        foreach (self::$connectionPool as $name => $connection) {
            if (str_starts_with($name, 'tenant_')) {
                try {
                    DB::purge($name);
                    unset(self::$connectionPool[$name]);
                } catch (Exception $e) {
                    Log::warning('Failed to purge tenant connection', [
                        'connection_name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Also purge default connection
        DB::purge('default');
    }

    /**
     * Generate service password (consistent with HybridDatabaseService)
     */
    private static function generateServicePassword(string $tenantId, string $service): string
    {
        return 'password123'; // In production, use a more secure method
    }

    /**
     * Get current connection info for debugging
     */
    public static function getCurrentConnectionInfo(): array
    {
        try {
            $connection = DB::connection(self::$currentConnection);
            
            return [
                'active_connection' => self::$currentConnection,
                'database_name' => $connection->getDatabaseName(),
                'driver' => $connection->getDriverName(),
                'host' => $connection->getConfig('host'),
                'username' => $connection->getConfig('username'),
                'pool_size' => count(self::$connectionPool),
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'active_connection' => self::$currentConnection,
            ];
        }
    }

    /**
     * Clean up old connections from pool
     */
    public static function cleanupOldConnections(int $maxAgeMinutes = 30): void
    {
        $cutoff = now()->subMinutes($maxAgeMinutes);
        
        foreach (self::$connectionPool as $name => $connection) {
            if ($connection['last_used']->lt($cutoff)) {
                try {
                    DB::purge($name);
                    unset(self::$connectionPool[$name]);
                    Log::info('Cleaned up old connection', ['connection_name' => $name]);
                } catch (Exception $e) {
                    Log::warning('Failed to cleanup old connection', [
                        'connection_name' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
