<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::UsersManage) === true;
    }

    public function rules(): array
    {
        return ['role' => ['required_without:is_active', 'filled', Rule::enum(UserRole::class)], 'is_active' => ['required_without:role', 'filled', 'boolean'], 'is_admin' => ['prohibited'], 'password' => ['prohibited']];
    }
}
