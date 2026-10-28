<?php

namespace App\Console\Commands;

use App\Actions\Conversions\RequestConversion;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PrepareFlac extends Command
{
    protected $signature = 'media:prepare-flac {--apply} {--owner=} {--limit=20} {--after=}';

    protected $description = 'Inspect or queue existing FLAC masters in bounded batches without replacing their files';

    public function handle(RequestConversion $conversions): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if (! $limit || $limit < 1 || $limit > 100) {
            $this->error('limit must be between 1 and 100.');

            return self::FAILURE;
        }
        $owner = $this->option('owner') ? User::find($this->option('owner')) : null;
        if ($this->option('apply') && (! $owner || ! $owner->is_active || $owner->role !== UserRole::Owner)) {
            $this->error('Apply requires an active super administrator via --owner=<id>.');

            return self::FAILURE;
        }
        $sources = MediaAsset::whereNull('source_asset_id')->where(fn ($q) => $q->where('codec', 'flac')->orWhereIn('mime_type', ['audio/flac', 'audio/x-flac']))->whereDoesntHave('conversions')->where('id', '>', $this->option('after') ?? '')->orderBy('id')->limit($limit + 1)->get();
        $report = ['dry_run' => ! $this->option('apply'), 'sources' => [], 'queued' => 0, 'errors' => [], 'next_cursor' => $sources->count() > $limit ? $sources->take($limit)->last()->id : null];
        foreach ($sources->take($limit) as $source) {
            $report['sources'][] = ['id' => $source->id, 'size_bytes' => $source->size_bytes];
            if ($this->option('apply')) {
                try {
                    $conversions->handle($owner, $source, $source->ownership_revision, 1);
                    $report['queued']++;
                } catch (ValidationException) {
                    $report['errors'][] = ['id' => $source->id, 'status' => 422];
                } catch (HttpExceptionInterface $error) {
                    $report['errors'][] = ['id' => $source->id, 'status' => $error->getStatusCode()];
                }
            }
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
