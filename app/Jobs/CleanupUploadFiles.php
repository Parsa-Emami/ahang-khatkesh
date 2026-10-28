<?php

namespace App\Jobs;

use App\Actions\Media\CleanupUpload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CleanupUploadFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public array $backoff = [10, 60, 300];

    public function __construct(public string $reservationId) {}

    public function handle(CleanupUpload $cleanup): void
    {
        $cleanup->handle($this->reservationId);
    }
}
