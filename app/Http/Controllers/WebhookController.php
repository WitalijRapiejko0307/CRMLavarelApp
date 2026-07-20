<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\BlacklistService;
use App\Services\SalesRenderService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * POST /api/webhook/lead
     *
     * Accepts a lead from the website and creates an order.
     * Replaces GAS doPost() in backend/Order.gs.
     *
     * Flow (Phase 3 additions):
     *   1. Verify X-Webhook-Token
     *   2. Create order with status "Позвонить"
     *   3. BlacklistService — append " НЕБЛАГОНАДЕЖНЫЙ КЛИЕНТ" if flagged
     *   4. SalesRenderService.sendOrder() — optional push if sr_default_item_id configured
     *
     * Headers:
     *   X-Webhook-Token: <webhook_secret from tenant_settings>
     *
     * Body (JSON):
     *   name, phone, offer, options (price in BYN), source
     */
    public function lead(Request $request): JsonResponse
    {
        $token = $request->header('X-Webhook-Token');

        if (!$token) {
            return response()->json(['result' => 'error', 'message' => 'Missing token'], 401);
        }

        // Locate tenant by webhook secret
        $settings = TenantSetting::withoutGlobalScopes()
            ->where('key', 'webhook_secret')
            ->get();

        $tenantId = null;
        foreach ($settings as $setting) {
            if ($setting->value === $token) {
                $tenantId = $setting->tenant_id;
                break;
            }
        }

        if (!$tenantId) {
            Log::warning('Webhook: invalid token', ['ip' => $request->ip()]);
            return response()->json(['result' => 'error', 'message' => 'Invalid token'], 401);
        }

        $data = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'phone'   => ['required', 'string', 'max:30'],
            'offer'   => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'numeric', 'min:0'],
            'source'  => ['nullable', 'string', 'max:50'],
        ]);

        $phone = PhoneNormalizer::normalize($data['phone']);

        // Set tenant context so TenantSetting::get() resolves correctly below
        app()->instance('current_tenant_id', $tenantId);

        // ── 1. Blacklist check ────────────────────────────────────────────────
        $fullName  = $data['name'];
        $apiKey    = TenantSetting::get('api_key_blacks_by', '');
        $listId    = TenantSetting::get('id_blacks_by', '');

        if ($apiKey && $listId) {
            $blacklist = new BlacklistService($apiKey, $listId);
            if ($blacklist->check($phone)) {
                $fullName .= ' НЕБЛАГОНАДЕЖНЫЙ КЛИЕНТ';
                Log::info('Webhook: blacklisted phone', ['phone' => $phone, 'tenant_id' => $tenantId]);
            }
        }

        // ── 2. Create order ───────────────────────────────────────────────────
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'  => $tenantId,
            'full_name'  => $fullName,
            'phone'      => $phone,
            'goods'      => $data['offer'] ? [$data['offer']] : [],
            'quantities' => [1],
            'prices'     => $data['offer'] ? [(float) ($data['options'] ?? 0)] : [],
            'status'     => 'Позвонить',
            'source'     => $data['source'] ?? 'site',
            'sms_log'    => null,
        ]);

        Log::info('Webhook: order created', ['order_id' => $order->id, 'tenant_id' => $tenantId]);

        // ── 3. Optional: push to SalesRender ─────────────────────────────────
        $srEnabled = TenantSetting::get('sr_enabled', '') === '1';
        $apiToken  = TenantSetting::get('api_token_call_centr', '');
        $companyId = TenantSetting::get('company_id_in_call_centre', '');
        $projectId = TenantSetting::get('project_id_in_call_centr', '');

        // Resolve sr_item_id from the matched product (looked up by offer name)
        $product  = $data['offer']
            ? Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', $data['offer'])
                ->first()
            : null;
        $srItemId = $product?->sr_item_id;

        if ($srEnabled && $apiToken && $companyId && $srItemId) {
            $srService = new SalesRenderService($apiToken, $companyId, $projectId);
            $srOrderId = $srService->sendOrder($order, $srItemId);

            if ($srOrderId) {
                $order->updateQuietly(['external_id' => $srOrderId]);
            }
        }

        return response()->json([
            'result'   => 'success',
            'order_id' => $order->id,
        ]);
    }
}

