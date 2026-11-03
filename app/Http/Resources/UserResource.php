<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'access_version' => $this->access_version,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'permissions' => $this->is_active ? array_map(fn (Permission $p) => $p->value, $this->role->permissions()) : [],
            'is_admin' => $this->is_active && $this->role->isPrivileged(),
            'is_active' => $this->is_active,
            'theme' => $this->theme ?? 'system',
            'locale' => $this->locale ?? 'fa-IR',
            'direction' => $this->direction ?? 'auto',
            'motion' => $this->motion ?? 'system',
            'time_zone' => $this->time_zone ?? 'Asia/Tehran',
            'crossfade_enabled' => (bool) ($this->crossfade_enabled ?? false),
            'crossfade_seconds' => (int) ($this->crossfade_seconds ?? 3),
            'crossfade_acknowledged' => $this->crossfade_acknowledged_at !== null,
            'loudness_normalization' => (bool) ($this->loudness_normalization ?? true),
            'offline_opt_in' => (bool) ($this->offline_opt_in ?? false),
            'offline_revocation_acknowledged' => $this->offline_revocation_acknowledged_at !== null,
            'offline_quota_bytes' => (int) ($this->offline_quota_bytes ?? 524288000),
            'mfa_enabled' => $this->hasEnabledTwoFactorAuthentication(),
        ];
    }
}
