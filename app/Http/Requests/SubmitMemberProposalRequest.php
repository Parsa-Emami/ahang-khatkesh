<?php

namespace App\Http\Requests;

use App\Models\Playlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitMemberProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = ['kind' => ['required', Rule::in(['playlist', 'track_metadata'])], 'idempotency_key' => ['required', 'uuid']];
        if ($this->input('kind') === 'playlist') {
            return $rules + [
                'payload' => ['required', 'array:name,description,mood,track_ids'],
                'payload.name' => ['required', 'string', 'max:160'],
                'payload.description' => ['nullable', 'string', 'max:3000'],
                'payload.mood' => ['required', Rule::in(Playlist::MOODS)],
                'payload.track_ids' => ['required', 'array', 'min:1', 'max:100'],
                'payload.track_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ];
        }

        return $rules + ['payload' => ['required', 'array:track_id,title'], 'payload.track_id' => ['required', 'integer', 'min:1'], 'payload.title' => ['required', 'string', 'max:255']];
    }
}
