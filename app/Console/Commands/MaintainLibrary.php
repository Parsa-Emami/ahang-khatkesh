<?php

namespace App\Console\Commands;

use App\Actions\Conversions\ConversionExecution;
use App\Actions\Imports\ImportExecution;
use App\Actions\Media\CleanupUpload;
use App\Actions\Media\CollectMediaGarbage;
use App\Actions\Media\RecoverUploads;
use App\Models\LibrarySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainLibrary extends Command
{
    protected $signature = 'library:maintain';

    protected $description = 'Recover expired import leases and upload reservations in bounded batches';

    public function handle(ImportExecution $imports, RecoverUploads $uploads, CollectMediaGarbage $garbage, CleanupUpload $cleanup, ConversionExecution $conversions): int
    {
        $result = ['conversions' => $conversions->recover(), 'imports' => $imports->recover(), 'uploads' => $uploads->handle(), 'pruned_locks' => $cleanup->pruneLocks()];
        if (config('music.garbage_collection_enabled')) {
            $cursor = LibrarySetting::whereKey(1)->value('media_gc_cursor') ?? '';
            $result['gc'] = $garbage->scan(true, 500, $cursor);
            DB::transaction(function () use ($result): void {
                LibrarySetting::whereKey(1)->increment('lock_version');
                LibrarySetting::whereKey(1)->update(['media_gc_cursor' => $result['gc']['next_cursor']]);
            });
            $result['deleted'] = $garbage->sweep();
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
