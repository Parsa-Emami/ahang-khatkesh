<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['blob_id', 'sha256', 'path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'uploaded_by'];

    protected $hidden = ['path', 'blob_id'];

    public function blob(): BelongsTo
    {
        return $this->belongsTo(MediaBlob::class, 'blob_id');
    }

    public function storagePath(): string
    {
        return $this->blob_id ? $this->blob->path : $this->path;
    }

    protected function casts(): array
    {
        return ['duration_seconds' => 'float', 'size_bytes' => 'integer'];
    }
}
