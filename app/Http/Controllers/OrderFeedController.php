<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Support\CallCenterOrderQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OrderFeedController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'tenant']);
    }

    /**
     * GET /api/orders/feed?since=2026-07-30T18:00:00Z
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $user   = Auth::user();
        $tenant = $user->tenant;
        $since  = $request->input('since')
            ? Carbon::parse($request->input('since'))
            : now()->subMinutes(5);

        if ($tenant->isCallCenter()) {
            $query = CallCenterOrderQuery::forTenant($tenant->id)
                ->with('tenant:id,name')
                ->where('updated_at', '>', $since);
        } else {
            $query = Order::query()
                ->where('tenant_id', $tenant->id)
                ->where('updated_at', '>', $since);
        }

        $orders = $query
            ->orderBy('updated_at')
            ->limit(100)
            ->get(['id', 'tenant_id', 'status', 'full_name', 'phone', 'updated_at']);

        $payload = $orders->map(function (Order $order) use ($tenant) {
            $item = [
                'id'         => $order->id,
                'status'     => $order->status,
                'full_name'  => $order->full_name,
                'phone'      => $order->phone,
                'updated_at' => $order->updated_at?->toIso8601String(),
            ];

            if ($tenant->isCallCenter()) {
                $item['store_name'] = $order->tenant?->name;
            }

            return $item;
        });

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'orders'      => $payload,
        ]);
    }
}
