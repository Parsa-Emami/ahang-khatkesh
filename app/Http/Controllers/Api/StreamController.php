<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\PlaybackMedia;
use App\Services\PrivateMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function __invoke(Request $request, Track $track, PrivateMedia $media, PlaybackMedia $playback): BinaryFileResponse
    {
        Gate::authorize('view', $track);
        $data = $request->validate(['quality' => ['sometimes', Rule::in(['auto', 'original', 'mp3-320'])]]);
        $track->load(['mediaAsset.blob', PlaybackMedia::EAGER]);
        $asset = $playback->select($track, $data['quality'] ?? 'auto');

        $response = response()->file($media->path($asset->storagePath()), ['Content-Type' => $asset->mime_type, 'Accept-Ranges' => 'bytes', 'Content-Disposition' => 'inline'])->setEtag($asset->sha256);
        $response->isNotModified($request);
        $ifRange = $request->headers->get('If-Range');
        $rangeApplies = $ifRange === null || $ifRange === $response->getEtag() || $ifRange === $response->getLastModified()?->format(DATE_RFC7231);
        if ($response->getStatusCode() === 200 && $request->isMethod('GET') && $rangeApplies && $request->headers->has('Range')) {
            $size = $response->getFile()->getSize();
            $valid = preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', $request->headers->get('Range'), $range) && ($range[1] !== '' || $range[2] !== '');
            $start = $valid && $range[1] !== '' ? (int) $range[1] : 0;
            $end = $valid && $range[2] !== '' ? (int) $range[2] : $size - 1;
            if ($valid && $range[1] === '') {
                $valid = $end > 0;
                $start = max(0, $size - $end);
                $end = $size - 1;
            }
            if (! $valid || $start >= $size || $start > $end) {
                $response->setStatusCode(416);
                $response->headers->set('Content-Range', 'bytes */'.$size);
            } else {
                $request->headers->set('Range', 'bytes='.$start.'-'.min($end, $size - 1));
            }
        }

        return $response;
    }
}
