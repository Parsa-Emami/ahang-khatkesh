<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadMediaRequest;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Services\MediaProbe;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MediaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MediaAsset::orderByDesc('id')->paginate(50));
    }

    public function store(UploadMediaRequest $request, MediaProbe $probe): JsonResponse
    {
        $file = $request->file('audio');
        $hash = hash_file('sha256', $file->getRealPath());
        $asset = MediaAsset::where('sha256', $hash)->first();
        if ($asset) {
            return response()->json(['data' => $asset, 'duplicate' => true]);
        }
        $metadata = $probe->inspect($file->getRealPath());
        $path = $file->store('audio', 'media');
        try {
            $asset = DB::transaction(function () use ($request, $file, $hash, $metadata, $path): MediaAsset {
                $asset = MediaAsset::create($metadata + ['sha256' => $hash, 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime_type' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'uploaded_by' => $request->user()->id]);
                AuditEvent::create(['user_id' => $request->user()->id, 'action' => 'media.uploaded', 'subject_id' => $asset->id, 'metadata' => ['size_bytes' => $file->getSize()]]);

                return $asset;
            });
        } catch (UniqueConstraintViolationException) {
            Storage::disk('media')->delete($path);

            return response()->json(['data' => MediaAsset::where('sha256', $hash)->firstOrFail(), 'duplicate' => true]);
        } catch (Throwable $error) {
            Storage::disk('media')->delete($path);
            throw $error;
        }

        return response()->json(['data' => $asset, 'duplicate' => false], 201);
    }
}
