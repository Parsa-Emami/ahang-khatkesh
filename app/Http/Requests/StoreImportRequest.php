<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Playlist;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ImportsManage) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:160'], 'description' => ['nullable', 'string', 'max:2000'], 'mood' => ['required', Rule::in(Playlist::MOODS)], 'manifest' => ['required', 'file', 'max:2048', 'extensions:json,csv'], 'idempotency_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,64}$/']];
    }
}
