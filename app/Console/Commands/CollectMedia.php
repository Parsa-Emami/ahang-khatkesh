<?php

namespace App\Console\Commands;

use App\Actions\Media\CollectMediaGarbage;
use Illuminate\Console\Command;

class CollectMedia extends Command
{
    protected $signature = 'media:gc {--mark} {--sweep} {--limit=500} {--after=}';

    protected $description = 'Dry-run by default; mark unreachable media, then separately sweep after retention';

    public function handle(CollectMediaGarbage $garbage): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (! $limit) {
            return self::INVALID;
        }
        $result = $garbage->scan((bool) $this->option('mark'), $limit, (string) $this->option('after'));
        $result['deleted'] = $this->option('sweep') ? $garbage->sweep($limit) : 0;
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
