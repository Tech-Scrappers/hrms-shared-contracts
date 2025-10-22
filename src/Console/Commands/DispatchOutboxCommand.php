<?php

namespace Shared\Console\Commands;

use Illuminate\Console\Command;
use Shared\Services\OutboxDispatcher;

class DispatchOutboxCommand extends Command
{
    protected $signature = 'outbox:dispatch {--limit=50}';

    protected $description = 'Dispatch pending events from the transaction outbox';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = (int) $this->option('limit');
        $count = $dispatcher->dispatchBatch($limit);
        $this->info("Dispatched {$count} event(s) from outbox.");
        return self::SUCCESS;
    }
}


