<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['sha256', 'path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'uploaded_by'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['duration_seconds' => 'float', 'size_bytes' => 'integer'];
    }
}
