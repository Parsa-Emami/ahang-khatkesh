<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditEventController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['action' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:/^[a-z][a-z0-9_.]+$/'], 'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'since' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'until' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...($request->filled('since') ? ['after_or_equal:since'] : [])], 'cursor' => ['sometimes', 'nullable', 'string', 'max:512']]);
        $query = AuditEvent::with('actor:id,name')
            ->when($data['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($data['since'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', CarbonImmutable::parse($v, 'UTC')->startOfDay()))
            ->when($data['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<', CarbonImmutable::parse($v, 'UTC')->addDay()->startOfDay()));

        return AuditEventResource::collection($query->orderByDesc('id')->cursorPaginate(25)->withQueryString());
    }
}
