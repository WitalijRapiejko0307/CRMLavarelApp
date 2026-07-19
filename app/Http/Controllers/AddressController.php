<?php

namespace App\Http\Controllers;

use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    private AddressService $service;

    public function __construct(AddressService $service)
    {
        $this->middleware(['auth', 'tenant']);
        $this->service = $service;
    }

    /**
     * GET /api/address/search?q=...
     * Proxy to Belpost dictionary-list.
     * Returns formatted address items for AddressSearchModal.
     */
    public function search(Request $request): JsonResponse
    {
        $q = $request->input('q', '');

        if (mb_strlen(trim($q)) < 2) {
            return response()->json(['items' => []]);
        }

        $raw   = $this->service->search($q);
        $items = $this->service->formatAll($raw);

        return response()->json(['items' => $items]);
    }
}
