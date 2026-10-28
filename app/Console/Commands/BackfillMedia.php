<?php

namespace App\Console\Commands;

use App\Actions\Media\BackfillMediaBlobs;
use Illuminate\Console\Command;

class BackfillMedia extends Command
{
    protected $signature = 'media:backfill {--apply} {--limit=100}';

    protected $description = 'Inspect or resumably link legacy media to immutable physical blobs';

    public function handle(BackfillMediaBlobs $backfill): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (! $limit) {
            $this->error('limit must be between 1 and 10000.');

            return self::INVALID;
        }
        $result = $backfill->handle((bool) $this->option('apply'), $limit);
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['errors'] || $result['remaining'] || (($result['inventory_complete'] ?? true) === false) ? self::FAILURE : self::SUCCESS;
    }
}
