<?php

namespace Shared\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * AuditLog Model
 * 
 * Tracks all changes to tenant data for compliance and debugging
 * 
 * @property string $id
 * @property string $tenant_id
 * @property string $table_name
 * @property string $record_id
 * @property string $action
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'table_name',
        'record_id',
        'action',
        'old_values',
        'new_values',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * Get tenant information via API (removed cross-service Eloquent relationship)
     * 
     * Note: Eloquent relationships should not cross service boundaries.
     * Use this helper method if you need tenant information.
     */
    public function getTenantInfo(): ?array
    {
        try {
            return app(\Shared\Services\TenantApiClient::class)->getTenant($this->tenant_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to fetch tenant info for audit log', [
                'audit_log_id' => $this->id,
                'tenant_id' => $this->tenant_id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Scope a query to only include logs for a specific tenant.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $tenantId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include logs for a specific table.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $tableName
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForTable($query, string $tableName)
    {
        return $query->where('table_name', $tableName);
    }

    /**
     * Scope a query to only include logs for a specific record.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $recordId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForRecord($query, string $recordId)
    {
        return $query->where('record_id', $recordId);
    }

    /**
     * Scope a query to only include logs for a specific action.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope a query to only include logs for a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForUser($query, string $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope a query to only include logs within a date range.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $startDate
     * @param  string  $endDate
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInDateRange($query, string $startDate, string $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Get the formatted old values for display.
     *
     * @return array
     */
    public function getFormattedOldValuesAttribute(): array
    {
        return $this->old_values ?? [];
    }

    /**
     * Get the formatted new values for display.
     *
     * @return array
     */
    public function getFormattedNewValuesAttribute(): array
    {
        return $this->new_values ?? [];
    }

    /**
     * Get the changes between old and new values.
     *
     * @return array
     */
    public function getChangesAttribute(): array
    {
        $oldValues = $this->old_values ?? [];
        $newValues = $this->new_values ?? [];

        $changes = [];

        foreach ($newValues as $key => $newValue) {
            $oldValue = $oldValues[$key] ?? null;
            
            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $changes;
    }

    /**
     * Check if the audit log has changes.
     * Matches Eloquent signature to avoid conflicts.
     */
    public function hasChanges($changes = null, $attributes = null): bool
    {
        // For audit logs we persist the change payload in `changes`/`new_values` fields
        // Treat presence of either as an indication of change content being available.
        if ($changes !== null) {
            return !empty($changes);
        }

        // Fall back to stored properties on the model
        return !empty($this->changes ?? null) || !empty($this->new_values ?? null);
    }

    /**
     * Get the action description for display.
     *
     * @return string
     */
    public function getActionDescriptionAttribute(): string
    {
        return match ($this->action) {
            'created' => 'Record created',
            'updated' => 'Record updated',
            'deleted' => 'Record deleted',
            'restored' => 'Record restored',
            'force_deleted' => 'Record permanently deleted',
            default => ucfirst($this->action),
        };
    }
}
