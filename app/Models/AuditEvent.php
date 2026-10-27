<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogicException;

class AuditEvent extends Model
{
    protected $fillable = ['user_id', 'action', 'subject_id', 'subject_type', 'request_id', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            $event->request_id ??= Context::get('request_id');
            $event->subject_type ??= Str::before($event->action, '.');
        });
        static::updating(fn () => throw new LogicException('Audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit events are append-only.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
