<?php

namespace Shared\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Shared\Models\AuditLog;
use Shared\Services\AuditService;

/**
 * AuditableTrait
 * 
 * Provides automatic audit logging for model changes
 * 
 * @mixin Model
 */
trait AuditableTrait
{
    /**
     * Boot the auditable trait.
     */
    protected static function bootAuditableTrait(): void
    {
        static::created(function (Model $model) {
            static::logAuditEvent($model, 'created');
        });

        static::updated(function (Model $model) {
            static::logAuditEvent($model, 'updated');
        });

        static::deleted(function (Model $model) {
            static::logAuditEvent($model, 'deleted');
        });

        // Register restored event only if the model uses SoftDeletes
        if (in_array('Illuminate\\Database\\Eloquent\\SoftDeletes', class_uses_recursive(static::class))) {
            static::restored(function (Model $model) {
                static::logAuditEvent($model, 'restored');
            });
        }
    }

    /**
     * Log an audit event for the model.
     *
     * @param  Model  $model
     * @param  string  $action
     * @return void
     */
    protected static function logAuditEvent(Model $model, string $action): void
    {
        try {
            // Skip audit logging for audit logs themselves to prevent recursion
            if ($model instanceof AuditLog) {
                return;
            }

            // Skip if audit logging is disabled
            if (config('audit.disabled', false)) {
                return;
            }

            $auditService = app(AuditService::class);
            $auditService->logModelChange($model, $action);

        } catch (\Exception $e) {
            // Log the error but don't break the main operation
            \Log::error('Failed to log audit event', [
                'model' => get_class($model),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the audit logs for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'record_id', 'id')
            ->where('table_name', $this->getTable())
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get the latest audit log for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function latestAuditLog()
    {
        return $this->hasOne(AuditLog::class, 'record_id', 'id')
            ->where('table_name', $this->getTable())
            ->latest();
    }

    /**
     * Get the creation audit log for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function creationAuditLog()
    {
        return $this->hasOne(AuditLog::class, 'record_id', 'id')
            ->where('table_name', $this->getTable())
            ->where('action', 'created');
    }

    /**
     * Get the last update audit log for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function lastUpdateAuditLog()
    {
        return $this->hasOne(AuditLog::class, 'record_id', 'id')
            ->where('table_name', $this->getTable())
            ->where('action', 'updated')
            ->latest();
    }

    /**
     * Get the deletion audit log for this model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function deletionAuditLog()
    {
        return $this->hasOne(AuditLog::class, 'record_id', 'id')
            ->where('table_name', $this->getTable())
            ->where('action', 'deleted');
    }

    /**
     * Get the audit trail for this model.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAuditTrail()
    {
        return $this->auditLogs()->get();
    }

    /**
     * Get the audit trail as a formatted array.
     *
     * @return array
     */
    public function getFormattedAuditTrail(): array
    {
        return $this->auditLogs()->get()->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'action_description' => $log->action_description,
                'changes' => $log->changes,
                'user_id' => $log->user_id,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'created_at' => $log->created_at,
            ];
        })->toArray();
    }

    /**
     * Check if the model has been modified since creation.
     *
     * @return bool
     */
    public function hasBeenModified(): bool
    {
        return $this->auditLogs()->where('action', 'updated')->exists();
    }

    /**
     * Get the number of times this model has been modified.
     *
     * @return int
     */
    public function getModificationCount(): int
    {
        return $this->auditLogs()->where('action', 'updated')->count();
    }

    /**
     * Get the last modification date.
     *
     * @return \Carbon\Carbon|null
     */
    public function getLastModifiedAt()
    {
        $lastUpdate = $this->lastUpdateAuditLog()->first();
        
        return $lastUpdate ? $lastUpdate->created_at : null;
    }

    /**
     * Get the creation date from audit logs.
     *
     * @return \Carbon\Carbon|null
     */
    public function getCreatedAtFromAudit()
    {
        $creation = $this->creationAuditLog()->first();
        
        return $creation ? $creation->created_at : null;
    }

    /**
     * Get the user who created this model.
     *
     * @return string|null
     */
    public function getCreatedBy()
    {
        $creation = $this->creationAuditLog()->first();
        
        return $creation ? $creation->user_id : null;
    }

    /**
     * Get the user who last modified this model.
     *
     * @return string|null
     */
    public function getLastModifiedBy()
    {
        $lastUpdate = $this->lastUpdateAuditLog()->first();
        
        return $lastUpdate ? $lastUpdate->user_id : null;
    }

    /**
     * Get the IP address of the user who created this model.
     *
     * @return string|null
     */
    public function getCreatedFromIp()
    {
        $creation = $this->creationAuditLog()->first();
        
        return $creation ? $creation->ip_address : null;
    }

    /**
     * Get the IP address of the user who last modified this model.
     *
     * @return string|null
     */
    public function getLastModifiedFromIp()
    {
        $lastUpdate = $this->lastUpdateAuditLog()->first();
        
        return $lastUpdate ? $lastUpdate->ip_address : null;
    }
}
