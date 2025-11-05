<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

interface OutboxRecordAccessor
{
    public function claimPendingBatch(int $limit = 50): array;
    public function markDispatched(int $id): void;
    public function markFailed(int $id, int $attempts, int $retryDelaySeconds): void;
}

class OutboxDispatcher
{
    public function __construct(
        protected EventPublisher $publisher,
        protected OutboxRecordAccessor $outbox,
    ) {}

    public function dispatchBatch(int $limit = 50): int
    {
        $records = $this->outbox->claimPendingBatch($limit);
        $dispatched = 0;

        foreach ($records as $record) {
            try {
                $eventClass = $record['headers']['event_class'] ?? null;
                if (! $eventClass || ! class_exists($eventClass)) {
                    throw new \RuntimeException('Unknown event class');
                }

                $event = new $eventClass(
                    $record['tenant_id'],
                    $record['payload'],
                    $record['headers']['user_id'] ?? null,
                    $record['headers']['external_tenant_id'] ?? null,
                    $record['headers']['external_ref_no'] ?? null,
                    $record['headers'] ?? []
                );

                $ok = $this->publisher->publish($event);
                if ($ok) {
                    $this->outbox->markDispatched($record['id']);
                    $dispatched++;
                } else {
                    $this->outbox->markFailed($record['id'], ($record['attempts'] ?? 0) + 1, 5);
                }
            } catch (\Throwable $e) {
                Log::error('Outbox dispatch failed', [
                    'id' => $record['id'],
                    'error' => $e->getMessage(),
                ]);
                $this->outbox->markFailed($record['id'], ($record['attempts'] ?? 0) + 1, 5);
            }
        }

        return $dispatched;
    }
}


