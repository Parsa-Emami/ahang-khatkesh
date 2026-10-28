<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MediaBlob extends Model
{
    use HasUuids;

    protected $fillable = ['sha256', 'size_bytes', 'path', 'mime_type', 'state', 'verified_at'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'verified_at' => 'immutable_datetime'];
    }
}
