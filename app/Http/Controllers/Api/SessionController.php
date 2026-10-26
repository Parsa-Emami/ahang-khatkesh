<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'user' => $user?->is_active ? new UserResource($user) : null]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:128']]);
        if (! Auth::attempt(['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'ایمیل یا رمز عبور درست نیست.']);
        }
        $request->session()->regenerate();

        return $this->show($request);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['csrf_token' => csrf_token()]]);
    }

    public function update(Request $request): UserResource
    {
        $data = $request->validate(['theme' => ['required', Rule::in(['light', 'dark', 'system'])]]);
        $request->user()->update($data);

        return new UserResource($request->user());
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers(), 'max:128'],
        ]);
        $request->user()->update(['password' => $data['password'], 'remember_token' => Str::random(60)]);
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        }
        $request->session()->regenerate(true);

        return response()->json(['data' => ['csrf_token' => csrf_token(), 'message' => 'رمز عبور تغییر کرد.']]);
    }
}
