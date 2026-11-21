<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SrPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * User actions on an order whose changes were not delivered to SalesRender.
 */
class SrPushController extends Controller
{
    public function retry(Order $order, SrPushService $service): JsonResponse
    {
        $this->authorize('update', $order);

        if (!$order->external_id || empty($order->sr_pending_changes)) {
            return response()->json(['message' => 'Нет неотправленных изменений.'], 422);
        }

        $service->retry($order, Auth::id());

        return response()->json(['state' => $order->fresh()->sr_sync_state]);
    }

    public function resolve(Request $request, Order $order, SrPushService $service): JsonResponse
    {
        $this->authorize('update', $order);

        $data = $request->validate(['choice' => ['required', 'in:keep_local,accept_sr']]);

        if ($order->sr_sync_state !== SrPushService::STATE_CONFLICT) {
            return response()->json(['message' => 'Конфликта по этой заявке нет.'], 422);
        }

        $failure = $service->resolve($order, $data['choice'], Auth::id());

        if ($failure) {
            return response()->json(['message' => $failure->message], 422);
        }

        return response()->json(['state' => $order->fresh()->sr_sync_state]);
    }
}
