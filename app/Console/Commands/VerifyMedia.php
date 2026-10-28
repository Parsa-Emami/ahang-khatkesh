<?php

namespace App\Console\Commands;

use App\Actions\Media\VerifyMedia as InspectMedia;
use Illuminate\Console\Command;

class VerifyMedia extends Command
{
    protected $signature = 'media:verify {--limit=1000} {--after=}';

    protected $description = 'Read-only media bytes and catalog reference integrity checks';

    public function handle(InspectMedia $verify): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (! $limit) {
            return self::INVALID;
        }
        $result = $verify->handle($limit, (string) $this->option('after'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['ok'] && $result['next_cursor'] === null ? self::SUCCESS : self::FAILURE;
    }
}
