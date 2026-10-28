<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'access_version' => $this->access_version, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role->value, 'role_label' => $this->role->label(), 'permissions' => $this->is_active ? array_map(fn (Permission $p) => $p->value, $this->role->permissions()) : [], 'is_admin' => $this->is_active && $this->role->isPrivileged(), 'is_active' => $this->is_active, 'theme' => $this->theme ?? 'system', 'mfa_enabled' => $this->hasEnabledTwoFactorAuthentication()];
    }
}
