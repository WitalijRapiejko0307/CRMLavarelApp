<?php

namespace App\Http\Controllers;

use App\Services\EvropostStoreSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EvropostStoreController extends Controller
{
    private EvropostStoreSearchService $service;

    public function __construct(EvropostStoreSearchService $service)
    {
        $this->middleware(['auth', 'tenant']);
        $this->service = $service;
    }

    /**
     * GET /api/europochta/stores/search?q=
     * Filter cached Europochta OPS directory (new API §2.2).
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        $result = $this->service->search($q, (int) Auth::user()->tenant_id);

        if (empty($result['ok'])) {
            $message = $result['message'] ?? 'Не удалось загрузить отделения Европочты';
            if (($result['error'] ?? '') === 'no_token') {
                $message = 'Подключите Европочту';
            }

            return response()->json([
                'message' => $message,
                'items'   => [],
            ], 422);
        }

        return response()->json(['items' => $result['items'] ?? []]);
    }
}
