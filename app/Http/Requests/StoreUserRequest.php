<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::UsersManage) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'], 'password' => ['required', 'string', Password::min(12)->letters()->numbers(), 'max:128'], 'is_admin' => ['sometimes', 'boolean', 'prohibited_with:role'], 'role' => ['sometimes', Rule::enum(UserRole::class)]];
    }
}
