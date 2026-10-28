<?php

namespace App\Jobs;

use App\Actions\Media\CollectMediaGarbage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteUnreferencedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 20;

    public array $backoff = [60, 300, 900];

    public function __construct(public string $path) {}

    public function handle(CollectMediaGarbage $garbage): void
    {
        $garbage->trackPath($this->path);
        $garbage->sweep(1, $this->path);
    }
}
