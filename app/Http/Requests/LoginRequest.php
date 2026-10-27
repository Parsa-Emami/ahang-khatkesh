<?php

namespace App\Http\Requests;

class LoginRequest extends \Laravel\Fortify\Http\Requests\LoginRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:128'], 'remember' => ['prohibited']];
    }
}
