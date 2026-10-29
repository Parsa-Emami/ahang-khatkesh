<?php

namespace App\Console\Commands;

use App\Services\Listening\ListeningLedger;
use Illuminate\Console\Command;

class PruneListening extends Command
{
    protected $signature = 'listening:prune {--limit=1000}';

    protected $description = 'Remove expired listening data in bounded per-account batches';

    public function handle(ListeningLedger $ledger): int
    {
        $this->line(json_encode(['deleted' => $ledger->prune(max(1, min(1000, (int) $this->option('limit'))))], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
