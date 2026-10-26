<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\PrivateMedia;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function __invoke(Track $track, PrivateMedia $media): BinaryFileResponse
    {
        Gate::authorize('view', $track);
        $track->load('mediaAsset');

        return response()->file($media->path($track->mediaAsset->path), ['Content-Type' => $track->mediaAsset->mime_type, 'Accept-Ranges' => 'bytes', 'Content-Disposition' => 'inline']);
    }
}
