<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\CreateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => UserResource::collection(User::orderBy('id')->get()), 'meta' => ['capacity' => LibrarySetting::MAX_USERS]]);
    }

    public function store(StoreUserRequest $request, CreateUser $create): UserResource
    {
        return new UserResource($create->handle($request->validated(), $request->user()));
    }

    public function update(Request $request, User $user): UserResource
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        abort_if($user->id === $request->user()->id && ! $data['is_active'], 422, 'نمی‌توانید حساب خودتان را غیرفعال کنید.');
        DB::transaction(function () use ($request, $user, $data): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $user->update($data);
            if (! $data['is_active'] && config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditEvent::create(['user_id' => $request->user()->id, 'action' => $data['is_active'] ? 'user.activated' : 'user.deactivated', 'subject_id' => (string) $user->id]);
        }, 5);

        return new UserResource($user);
    }
}
