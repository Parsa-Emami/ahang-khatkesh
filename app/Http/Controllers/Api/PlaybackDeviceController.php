<?php

namespace App\Http\Controllers\Api;

use App\Actions\Playback\ManagePlaybackLease;
use App\Actions\Playback\PollDeviceCommands;
use App\Actions\Playback\RegisterPlaybackDevice;
use App\Actions\Playback\RevokePlaybackDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaybackDeviceCommandResource;
use App\Http\Resources\PlaybackDeviceResource;
use App\Http\Resources\PlaybackLeaseResource;
use App\Models\PlaybackDevice;
use App\Models\PlaybackLease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaybackDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = PlaybackDevice::where('user_id', $request->user()->id)
            ->whereNull('revoked_at')
            ->orderByDesc('last_seen_at')
            ->get();
        $lease = PlaybackLease::with(['device', 'transferTarget'])->where('user_id', $request->user()->id)->first();

        return response()->json([
            'data' => [
                'devices' => PlaybackDeviceResource::collection($devices),
                'lease' => $lease ? new PlaybackLeaseResource($lease) : null,
            ],
        ]);
    }

    public function store(Request $request, RegisterPlaybackDevice $register): JsonResponse
    {
        $data = $request->validate([
            'device_key' => ['required', 'string', 'min:16', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $device = $register->handle(
            $request->user(),
            $data['device_key'],
            $request->session()->getId(),
            $data['name'],
            $request->userAgent(),
        );

        return response()->json(['data' => new PlaybackDeviceResource($device)], 201);
    }

    public function claim(Request $request, PlaybackDevice $device, ManagePlaybackLease $leases): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => new PlaybackLeaseResource($leases->claim($request->user(), $device)->load(['device', 'transferTarget']))]);
    }

    public function heartbeat(Request $request, PlaybackDevice $device, ManagePlaybackLease $leases): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);
        $data = $request->validate(['lease_token' => ['required', 'string', 'max:64']]);

        return response()->json(['data' => new PlaybackLeaseResource($leases->heartbeat($request->user(), $device, $data['lease_token'])->load(['device', 'transferTarget']))]);
    }

    public function transfer(Request $request, PlaybackDevice $device, ManagePlaybackLease $leases): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);
        $data = $request->validate([
            'lease_token' => ['required', 'string', 'max:64'],
            'target_device_id' => ['required', 'uuid'],
        ]);
        $target = PlaybackDevice::where('user_id', $request->user()->id)->whereKey($data['target_device_id'])->firstOrFail();

        return response()->json(['data' => new PlaybackLeaseResource($leases->offerTransfer($request->user(), $device, $data['lease_token'], $target)->load(['device', 'transferTarget']))]);
    }

    public function acceptTransfer(Request $request, PlaybackDevice $device, ManagePlaybackLease $leases): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => new PlaybackLeaseResource($leases->acceptTransfer($request->user(), $device)->load(['device', 'transferTarget']))]);
    }

    public function rejectTransfer(Request $request, PlaybackDevice $device, ManagePlaybackLease $leases): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => new PlaybackLeaseResource($leases->rejectTransfer($request->user(), $device)->load(['device', 'transferTarget']))]);
    }

    public function commands(Request $request, PlaybackDevice $device, PollDeviceCommands $poll): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => PlaybackDeviceCommandResource::collection($poll->handle($request->user(), $device))]);
    }

    public function destroy(Request $request, PlaybackDevice $device, RevokePlaybackDevice $revoke): JsonResponse
    {
        abort_unless((int) $device->user_id === (int) $request->user()->id, 404);
        $revoke->handle($request->user(), $device);

        return response()->json(['data' => ['revoked' => true]]);
    }
}
