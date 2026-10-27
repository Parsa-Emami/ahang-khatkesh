<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\CreateUser;
use App\Actions\UpdateUserAccess;
use App\Enums\Permission;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserAccessRequest;
use App\Http\Resources\UserResource;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = collect(UserRole::cases())->filter(fn (UserRole $role) => ! $role->isPrivileged() || $request->user()->hasPermission(Permission::RolesManage))->map(fn (UserRole $role) => ['value' => $role->value, 'label' => $role->label()])->values();

        return response()->json(['data' => UserResource::collection(User::orderBy('id')->get()), 'meta' => ['capacity' => LibrarySetting::MAX_USERS, 'assignable_roles' => $roles]]);
    }

    public function store(StoreUserRequest $request, CreateUser $create): UserResource
    {
        return new UserResource($create->handle($request->validated(), $request->user()));
    }

    public function update(UpdateUserAccessRequest $request, User $user, UpdateUserAccess $update): UserResource
    {
        $oldRole = $user->role;
        $updated = $update->handle($user, $request->user(), $request->validated());
        if ($updated->id === $request->user()->id && $updated->role !== $oldRole) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new UserResource($updated);
    }
}
