<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OutboxEnqueuer
{
    /**
     * Persist an event into the local service outbox within caller's transaction.
     */
    public function enqueue(string $tenantId, string $aggregateType, string $aggregateId, string $eventType, array $payload, array $headers = []): void
    {
        DB::table('events_outbox')->insert([
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'headers' => ! empty($headers) ? json_encode($headers) : null,
            'tenant_id' => $tenantId,
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}


