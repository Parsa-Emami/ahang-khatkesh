<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Track;
use App\Services\PlaybackMedia;
use App\Services\PrivateMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function __invoke(Request $request, Track $track, PrivateMedia $media, PlaybackMedia $playback): BinaryFileResponse|Response
    {
        Gate::authorize('view', $track);
        $data = $request->validate(['quality' => ['sometimes', Rule::in(['auto', 'original', 'mp3-320'])]]);
        $track->load(['mediaAsset.blob', PlaybackMedia::EAGER]);
        $asset = $playback->select($track, $data['quality'] ?? 'auto');
        $absolute = $media->path($asset->storagePath());

        if (config('music.stream_accel_enabled')) {
            $internal = rtrim((string) config('music.stream_internal_prefix'), '/').'/'.ltrim(str_replace('\\', '/', $asset->storagePath()), '/');
            $response = response('', 200, [
                (string) config('music.stream_accel_header') => $internal,
                'Content-Type' => $asset->mime_type,
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, no-store',
            ]);
            $response->setEtag($asset->sha256);
            $response->isNotModified($request);

            return $response;
        }

        $response = response()->file($absolute, [
            'Content-Type' => $asset->mime_type,
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline',
        ])->setEtag($asset->sha256);
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
