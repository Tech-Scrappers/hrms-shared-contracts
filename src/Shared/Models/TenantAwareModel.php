<?php

namespace Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Shared\Contracts\TenantAwareInterface;
use Shared\Traits\TenantAwareTrait;

abstract class TenantAwareModel extends Model implements TenantAwareInterface
{
    use HasUuids, TenantAwareTrait;

    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = null;

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically set tenant_id when creating
        static::creating(function ($model) {
            if (empty($model->tenant_id) && request()->has('tenant_id')) {
                $model->tenant_id = request()->get('tenant_id');
            }
        });

        // Automatically scope queries to current tenant
        static::addGlobalScope('tenant', function ($builder) {
            if (request()->has('tenant_id')) {
                $builder->where('tenant_id', request()->get('tenant_id'));
            }
        });
    }

    /**
     * Get the database connection for the model.
     *
     * IMPORTANT: Always use the framework's current default connection.
     * The HybridTenantDatabaseMiddleware/DatabaseConnectionManager are
     * responsible for switching connections per-request.
     */
    public function getConnection()
    {
        return parent::getConnection();
    }

    /**
     * Configure tenant connection if it doesn't exist
     */
    private function configureTenantConnection(string $tenantId, string $service): bool
    {
        try {
            $connectionName = "tenant_{$tenantId}_{$service}";
            $databaseName = "tenant_{$tenantId}_{$service}";
            $username = "tenant_{$tenantId}_{$service}";
            $password = $this->generateSecurePassword($tenantId, $service);

            // Configure the connection
            config([
                "database.connections.{$connectionName}" => [
                    'driver' => 'pgsql',
                    'host' => config('database.connections.pgsql.host'),
                    'port' => config('database.connections.pgsql.port'),
                    'database' => $databaseName,
                    'username' => $username,
                    'password' => $password,
                    'charset' => 'utf8',
                    'prefix' => '',
                    'prefix_indexes' => true,
                    'search_path' => 'public',
                    'sslmode' => 'require',
                ],
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate a cryptographically secure password for tenant database
     */
    private function generateSecurePassword(string $tenantId, string $service): string
    {
        // Generate a secure random password using OpenSSL
        $randomBytes = random_bytes(32);
        $password = base64_encode($randomBytes);

        // Add tenant and service context for uniqueness
        $context = hash('sha256', $tenantId.$service.config('app.key'));

        // Combine and hash for final password
        return hash('sha256', $password.$context);
    }

    /**
     * Auto-detect the service name from the current environment
     */
    private function detectServiceFromEnvironment(): string
    {
        // Check if we're in a specific service directory
        $appPath = app_path();

        if (str_contains($appPath, 'employee-service')) {
            return 'employee';
        }

        if (str_contains($appPath, 'core-service')) {
            return 'core';
        }

        if (str_contains($appPath, 'identity-service')) {
            return 'identity';
        }

        // Check environment variable
        $service = env('SERVICE_NAME');
        if ($service) {
            return $service;
        }

        // Default fallback
        return 'employee';
    }

    /**
     * Get the table associated with the model.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table ?? str_replace('\\', '', Str::snake(class_basename($this)));
    }

    /**
     * Create a new instance of the given model.
     *
     * @param  array  $attributes
     * @param  bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);

        // Set tenant_id if available
        if (request()->has('tenant_id')) {
            $model->tenant_id = request()->get('tenant_id');
        }

        return $model;
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQuery()
    {
        $builder = parent::newQuery();

        // Apply tenant scope if tenant_id is available
        if (request()->has('tenant_id')) {
            $builder->where('tenant_id', request()->get('tenant_id'));
        }

        return $builder;
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newQueryWithoutScopes()
    {
        $builder = parent::newQueryWithoutScopes();

        // Apply tenant scope if tenant_id is available
        if (request()->has('tenant_id')) {
            $builder->where('tenant_id', request()->get('tenant_id'));
        }

        return $builder;
    }

    /**
     * Get the tenant ID for this model
     */
    public function getTenantId(): string
    {
        return $this->tenant_id ?? '';
    }

    /**
     * Set the tenant ID for this model
     */
    public function setTenantId(string $tenantId): void
    {
        $this->tenant_id = $tenantId;
    }

    /**
     * Check if the model belongs to a specific tenant
     */
    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenant_id === $tenantId;
    }

    /**
     * Scope a query to only include records for a specific tenant
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include records for the current tenant
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForCurrentTenant($query)
    {
        if (request()->has('tenant_id')) {
            return $query->where('tenant_id', request()->get('tenant_id'));
        }

        return $query;
    }
}
