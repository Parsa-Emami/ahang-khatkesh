<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::MediaManage) === true;
    }

    public function rules(): array
    {
        return ['reservation_id' => ['required', 'uuid'], 'reuse_existing' => ['sometimes', 'boolean'], 'audio' => ['required', 'file', 'max:'.config('music.max_upload_kilobytes'), 'extensions:mp3,m4a,ogg,wav,flac', 'mimetypes:audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,video/mp4,audio/ogg,application/ogg,audio/wav,audio/x-wav,audio/vnd.wave,audio/flac,audio/x-flac']];
    }
}
