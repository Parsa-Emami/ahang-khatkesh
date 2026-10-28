<?php

namespace App\Http\Controllers\Api\Admin;

use App\Actions\AuthorizeLibraryWrite;
use App\Actions\Media\ReserveStorage;
use App\Actions\Media\StorageBudget;
use App\Http\Controllers\Controller;
use App\Models\LibrarySetting;
use App\Models\MediaAsset;
use App\Models\StorageReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StorageController extends Controller
{
    public function show(StorageBudget $budget): JsonResponse
    {
        return response()->json(['data' => $budget->snapshot() + ['inventory_ready' => (bool) LibrarySetting::whereKey(1)->value('media_inventory_ready'), 'legacy_assets' => MediaAsset::whereNull('blob_id')->count(), 'pending_cleanup' => StorageReservation::where('status', 'cleanup_pending')->count()]]);
    }

    public function store(Request $request, ReserveStorage $reserve): JsonResponse
    {
        $data = $request->validate(['size_bytes' => ['required', 'integer', 'min:1'], 'purpose' => ['required', Rule::in(['audio', 'cover'])]]);
        $reservation = $reserve->handle($request->user(), $data['size_bytes'], $data['purpose']);

        return response()->json(['data' => ['id' => $reservation->id, 'size_bytes' => $reservation->size_bytes, 'purpose' => $reservation->purpose, 'expires_at' => $reservation->expires_at->toIso8601String()]], 201);
    }

    public function destroy(Request $request, StorageReservation $reservation, AuthorizeLibraryWrite $authorize): JsonResponse
    {
        DB::transaction(function () use ($request, $reservation, $authorize): void {
            $actor = $authorize->handle($request->user());
            $locked = StorageReservation::whereKey($reservation->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->purpose === 'conversion', 409, 'رزرو تبدیل فقط از مسیر لغو تبدیل آزاد می‌شود.');
            if ($locked->status === 'released') {
                return;
            }
            abort_unless($locked->status === 'reserved', 409, 'آپلود شروع‌شده باید با چرخهٔ بازیابی آزاد شود.');
            $locked->update(['status' => 'released']);
        }, 5);

        return response()->json(['data' => ['released' => true]]);
    }
}
