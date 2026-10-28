<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StorageReservation extends Model
{
    use HasUuids;

    public const CHARGED = ['reserved', 'writing', 'cleanup_pending'];

    protected $guarded = [];

    protected $hidden = ['staging_path', 'candidate_path', 'lease_token', 'intent'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'access_version' => 'integer', 'expires_at' => 'immutable_datetime', 'intent' => 'array'];
    }
}
