<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Playlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewCatalogChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::CatalogManage) ?? false;
    }

    public function rules(): array
    {
        $rules = ['operation' => ['required', Rule::in(['metadata', 'playlists', 'move', 'merge', 'repair', 'proposal'])]];
        $operation = $this->input('operation');
        if ($operation === 'metadata') {
            return $rules + [
                'rows' => ['required', 'array', 'min:1', 'max:100'],
                'rows.*' => ['required', 'array:id,revision,title,credits,primary_release_id,recording_identity,recording_provenance'],
                'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'],
                'rows.*.revision' => ['required', 'integer', 'min:0'],
                'rows.*.title' => ['required', 'string', 'max:255'],
                'rows.*.primary_release_id' => ['present', 'nullable', 'integer', 'min:1'],
                'rows.*.credits' => ['required', 'array', 'min:1', 'max:30'],
                'rows.*.credits.*' => ['required', 'array:artist_id,role'],
                'rows.*.credits.*.artist_id' => ['required', 'integer', 'min:1'],
                'rows.*.credits.*.role' => ['required', Rule::in(['primary', 'featured', 'composer', 'producer'])],
                'rows.*.recording_identity' => ['sometimes', 'nullable', 'string', 'regex:/^(isrc:[A-Z]{2}[A-Z0-9]{3}[0-9]{7}|manual:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/D'],
                'rows.*.recording_provenance' => ['sometimes', 'nullable', 'string', 'max:160'],
            ];
        }
        if ($operation === 'playlists') {
            return $rules + [
                'rows' => ['required', 'array', 'min:1', 'max:25'],
                'rows.*' => ['required', 'array:id,revision,name,description,mood,status'],
                'rows.*.id' => ['required', 'integer', 'min:1', 'distinct'],
                'rows.*.revision' => ['required', 'integer', 'min:0'],
                'rows.*.name' => ['required', 'string', 'max:160'],
                'rows.*.description' => ['nullable', 'string', 'max:3000'],
                'rows.*.mood' => ['required', Rule::in(Playlist::MOODS)],
                'rows.*.status' => ['required', Rule::in([Playlist::PUBLISHED, Playlist::DRAFT, Playlist::ARCHIVED])],
            ];
        }
        if ($operation === 'move') {
            return $rules + ['source_playlist_id' => ['required', 'integer', 'min:1'], 'target_playlist_id' => ['required', 'integer', 'min:1', 'different:source_playlist_id'], 'source_revision' => ['required', 'integer', 'min:0'], 'target_revision' => ['required', 'integer', 'min:0'], 'track_ids' => ['required', 'array', 'min:1', 'max:100'], 'track_ids.*' => ['required', 'integer', 'min:1', 'distinct']];
        }
        if ($operation === 'merge') {
            return $rules + ['canonical_id' => ['required', 'integer', 'min:1'], 'canonical_revision' => ['required', 'integer', 'min:0'], 'source_ids' => ['required', 'array', 'min:1', 'max:20'], 'source_ids.*' => ['required', 'integer', 'min:1', 'distinct'], 'recording_identity' => ['required', 'string', 'max:160'], 'recording_provenance' => ['required', 'string', 'max:160']];
        }
        if ($operation === 'repair') {
            return $rules + ['track_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:0'], 'asset_id' => ['required', 'uuid'], 'recording_identity' => ['required', 'string', 'max:160'], 'recording_provenance' => ['required', 'string', 'max:160']];
        }
        if ($operation === 'proposal') {
            return $rules + ['proposal_id' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:0'], 'publish' => ['sometimes', 'boolean']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('operation') !== 'metadata') {
                return;
            }
            foreach ($this->input('rows', []) as $index => $row) {
                $credits = collect($row['credits']);
                if (! $credits->contains('role', 'primary') || $credits->map(fn ($credit) => $credit['artist_id'].':'.$credit['role'])->unique()->count() !== $credits->count()) {
                    $validator->errors()->add('rows.'.$index.'.credits', __('catalog.credits'));
                }
                if (array_key_exists('recording_identity', $row) !== array_key_exists('recording_provenance', $row) || (filled($row['recording_identity'] ?? null) !== filled($row['recording_provenance'] ?? null))) {
                    $validator->errors()->add('rows.'.$index.'.recording_identity', __('catalog.identity_pair'));
                }
            }
        }];
    }
}
