<?php

namespace App\Console\Commands;

use App\Actions\Ops\VerifyUpgrade as InspectUpgrade;
use Illuminate\Console\Command;

class VerifyUpgrade extends Command
{
    protected $signature = 'ops:verify-upgrade {--limit=1000} {--after=}';

    protected $description = 'Continuable post-upgrade catalog and media verification';

    public function handle(InspectUpgrade $verify): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (! $limit) {
            return self::INVALID;
        }
        $result = $verify->handle($limit, (string) $this->option('after'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
