<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Catalog\CatalogHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogHealthController extends Controller
{
    public function __invoke(Request $request, CatalogHealth $health): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['sometimes', 'integer', 'between:1,10000']]);
        $page = $health->page($data['q'] ?? null);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }
}
