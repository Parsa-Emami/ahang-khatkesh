<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['blob_id', 'sha256', 'path', 'original_name', 'mime_type', 'size_bytes', 'duration_seconds', 'uploaded_by', 'source_asset_id', 'ownership_revision', 'codec', 'sample_rate', 'channels'];

    protected $attributes = ['ownership_revision' => 1];

    protected $hidden = ['path', 'blob_id'];

    public function blob(): BelongsTo
    {
        return $this->belongsTo(MediaBlob::class, 'blob_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MediaConversion::class, 'source_asset_id');
    }

    public function isFlac(): bool
    {
        return $this->codec === 'flac' || in_array($this->mime_type, ['audio/flac', 'audio/x-flac'], true);
    }

    public function storagePath(): string
    {
        return $this->blob_id ? $this->blob->path : $this->path;
    }

    protected function casts(): array
    {
        return ['duration_seconds' => 'float', 'size_bytes' => 'integer', 'ownership_revision' => 'integer', 'channels' => 'integer', 'sample_rate' => 'integer'];
    }
}
