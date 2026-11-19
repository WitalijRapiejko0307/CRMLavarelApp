<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\TenantSetting;
use App\Services\BlacklistService;
use App\Services\CustomerService;
use App\Services\OrderAssignmentService;
use App\Services\ProductVariationService;
use App\Services\SalesRenderService;
use App\Support\PhoneNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        protected OrderAssignmentService $orderAssignment,
        protected CustomerService $customers
    ) {}

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
     *   name, phone, offer, options (price in BYN), comment (optional, max 2000), source
     *   variation (optional): variation NAME of the product (like offer; case-insensitive);
     *     an unrecognised value is ignored (never rejects the lead); only used for products that have variations
     *   optional UTM: utm_source, utm_medium, utm_campaign
     *     or nested utm.source, utm.medium, utm.campaign
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

        $tenant = \App\Models\Tenant::find($tenantId);

        if (!$tenant || !$tenant->acceptsLeads()) {
            return response()->json(['result' => 'error', 'message' => 'Leads are not accepted'], 403);
        }

        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'phone'         => ['required', 'string', 'max:30'],
            'offer'         => ['nullable', 'string', 'max:255'],
            'options'       => ['nullable', 'numeric', 'min:0'],
            'comment'       => ['nullable', 'string', 'max:2000'],
            'variation'     => ['nullable', 'max:255'],
            'source'        => ['nullable', 'string', 'max:2000'],
            'utm_source'    => ['nullable', 'string', 'max:255'],
            'utm_medium'    => ['nullable', 'string', 'max:255'],
            'utm_campaign'  => ['nullable', 'string', 'max:255'],
            'utm'           => ['nullable', 'array'],
            'utm.source'    => ['nullable', 'string', 'max:255'],
            'utm.medium'    => ['nullable', 'string', 'max:255'],
            'utm.campaign'  => ['nullable', 'string', 'max:255'],
        ]);

        $utm = is_array($data['utm'] ?? null) ? $data['utm'] : [];
        $utmOrNull = static function ($value): ?string {
            return ($value === null || $value === '') ? null : (string) $value;
        };
        $utmSource   = $utmOrNull($data['utm_source'] ?? ($utm['source'] ?? null));
        $utmMedium   = $utmOrNull($data['utm_medium'] ?? ($utm['medium'] ?? null));
        $utmCampaign = $utmOrNull($data['utm_campaign'] ?? ($utm['campaign'] ?? null));

        $comment = trim((string) ($data['comment'] ?? ''));
        $comment = $comment === '' ? null : $comment;

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

        // Product matched by offer name (sr_item_id and variations are resolved from it)
        $product = $data['offer']
            ? Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('name', $data['offer'])
                ->first()
            : null;

        // Variation is given by name (like the offer) and must exist in the product's variations;
        // the SalesRender number is taken from the matched variation.
        $variation = null;
        $rawVariation = trim((string) ($data['variation'] ?? ''));
        if ($product && $rawVariation !== '') {
            $variation = app(ProductVariationService::class)->findByName($product, $rawVariation);
            if (!$variation) {
                Log::info('Webhook: variation ignored (no variation with this name)', [
                    'tenant_id' => $tenantId,
                    'offer'     => $data['offer'],
                    'variation' => $rawVariation,
                ]);
            }
        }

        // ── 2. Create order ───────────────────────────────────────────────────
        $order = Order::withoutGlobalScopes()->create([
            'tenant_id'     => $tenantId,
            'full_name'     => $fullName,
            'phone'         => $phone,
            'goods'         => $data['offer'] ? [$data['offer']] : [],
            'quantities'    => [1],
            'variations'    => ($data['offer'] && $variation)
                ? [['number' => $variation->number, 'name' => $variation->name]]
                : null,
            'prices'        => $data['offer'] ? [(float) ($data['options'] ?? 0)] : [],
            'comment'       => $comment,
            'status'        => 'Позвонить',
            'source'        => $data['source'] ?? 'site',
            'utm_source'    => $utmSource,
            'utm_medium'    => $utmMedium,
            'utm_campaign'  => $utmCampaign,
            'sms_log'       => null,
        ]);

        Log::info('Webhook: order created', ['order_id' => $order->id, 'tenant_id' => $tenantId]);

        $this->orderAssignment->assignCallCenter($order);

        // Auto-fill name / address / delivery from the store's customer base.
        // Goods, prices and comment of the new lead are never touched.
        try {
            $this->customers->applyPrefill($order);
        } catch (\Throwable $e) {
            Log::warning('Webhook: customer prefill failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        // ── 3. Optional: push to SalesRender ─────────────────────────────────
        $hasInternalCc = $this->orderAssignment->storeHasInternalCallCenter($tenantId);
        $srEnabled = TenantSetting::get('sr_enabled', '') === '1';
        $apiToken  = TenantSetting::get('api_token_call_centr', '');
        $companyId = TenantSetting::get('company_id_in_call_centre', '');
        $projectId = TenantSetting::get('project_id_in_call_centr', '');

        $srItemId = $product?->sr_item_id;

        if (!$hasInternalCc && $srEnabled && $apiToken && $companyId && $srItemId && $tenant && !$tenant->isReadOnly()) {
            $srService = new SalesRenderService($apiToken, $companyId, $projectId);
            $srOrderId = $srService->sendOrder($order, $srItemId, $variation?->number);

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

