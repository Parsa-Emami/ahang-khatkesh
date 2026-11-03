<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlaybackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'listening_revision' => ['sometimes', 'integer', 'min:0'],
            'private_mode' => ['sometimes', 'boolean'],
            'device_id' => ['sometimes', 'nullable', 'uuid'],
            'lease_token' => ['sometimes', 'nullable', 'string', 'max:64'],
            'track_id' => ['present', 'nullable', 'integer'],
            'queue' => ['present', 'array', 'max:500'],
            'queue.*' => ['required', 'integer', 'distinct'],
            'position_seconds' => ['required', 'numeric', 'min:0', 'max:14400'],
            'volume' => ['required', 'numeric', 'between:0,1'],
            'shuffle' => ['required', 'boolean'],
            'repeat_mode' => ['required', Rule::in(['off', 'all', 'one'])],
            'revision' => ['required', 'integer', 'min:0'],
        ];
    }
}
