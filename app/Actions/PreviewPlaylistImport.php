<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\PlaylistImport;
use App\Models\User;
use App\Services\Imports\ManifestReader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PreviewPlaylistImport
{
    public function __construct(private ManifestReader $reader, private AuthorizeLibraryWrite $authorize, private ResolveImportPlan $plans) {}

    public function handle(User $user, UploadedFile $file, array $data): PlaylistImport
    {
        $rows = $this->reader->read($file);
        $validated = Validator::make(['tracks' => $rows], ['tracks' => ['required', 'array', 'min:1', 'max:'.config('music.max_tracks_per_import')], 'tracks.*' => ['required', 'array:asset_id,title,artist,album,artist_id,release_id'], 'tracks.*.asset_id' => ['required', 'uuid', 'distinct'], 'tracks.*.title' => ['required', 'string', 'max:255'], 'tracks.*.artist' => ['required', 'string', 'max:255'], 'tracks.*.album' => ['nullable', 'string', 'max:255'], 'tracks.*.artist_id' => ['nullable', 'integer', 'exists:artists,id'], 'tracks.*.release_id' => ['nullable', 'integer', 'exists:releases,id']])->validate();
        $rows = array_map(fn (array $row): array => ['asset_id' => $row['asset_id'], 'title' => trim($row['title']), 'artist' => trim($row['artist']), 'album' => isset($row['album']) ? trim($row['album']) : null] + (filled($row['artist_id'] ?? null) ? ['artist_id' => (int) $row['artist_id']] : []) + (filled($row['release_id'] ?? null) ? ['release_id' => (int) $row['release_id']] : []), $validated['tracks']);
        foreach ($rows as $row) {
            if ($row['title'] === '' || $row['artist'] === '') {
                throw ValidationException::withMessages(['tracks' => 'عنوان و هنرمند نباید خالی باشند.']);
            }
        }
        $payload = ['name' => trim($data['name']), 'description' => $data['description'] ?? null, 'mood' => $data['mood'], 'rows' => $rows];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $key = ['created_by' => $user->id, 'idempotency_key' => $data['idempotency_key']];
        try {
            $import = DB::transaction(function () use ($key, $payload, $hash, $user): PlaylistImport {
                $this->authorize->handle($user);
                $import = PlaylistImport::where($key)->first();
                if (! $import) {
                    $plan = $this->plans->handle($payload['rows'], true);
                    $import = PlaylistImport::create($key + array_replace($payload, ['rows' => $plan['rows']]) + ['payload_hash' => $hash, 'status' => PlaylistImport::READY, 'report' => ['validated_tracks' => count($plan['rows']), 'source_rows' => $payload['rows'], 'plan_hash' => $plan['hash'], 'plan_version' => 2, 'actor_epoch' => $user->access_version]]);
                }
                if ($import->wasRecentlyCreated) {
                    AuditEvent::create(['user_id' => $user->id, 'action' => 'import.previewed', 'subject_id' => $import->id]);
                }

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            $import = PlaylistImport::where($key)->firstOrFail();
        }
        abort_unless(hash_equals($import->payload_hash, $hash), 409, 'این کلید قبلاً برای فایل یا اطلاعات دیگری استفاده شده است.');

        return $import;
    }
}
