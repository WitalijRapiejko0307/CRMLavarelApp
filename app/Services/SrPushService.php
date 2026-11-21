<?php

namespace App\Services;

use App\Jobs\PushOrderToSalesRenderJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\PhoneNormalizer;
use App\Support\SrOrderMapper;
use App\Support\SrResult;
use App\Support\SrStatusMap;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sends CRM edits of an already exported order to SalesRender (orderMutation.updateOrder).
 *
 * Model: each unsent edit is kept in orders.sr_pending_changes as {from, to}. Before sending, the
 * SalesRender value is compared with `from` / `to`:
 *   SR == to    → already there, drop the pending edit
 *   SR == from  → push
 *   otherwise   → the call center changed it independently → conflict, nothing is overwritten
 * The pull job (SyncSalesRenderJob) uses reconcile() and never overwrites pending fields.
 *
 * Statuses: pending → retrying → failed | blocked | conflict | synced
 */
class SrPushService
{
    public const STATE_SYNCED   = 'synced';
    public const STATE_PENDING  = 'pending';
    public const STATE_RETRYING = 'retrying';
    public const STATE_FAILED   = 'failed';
    public const STATE_BLOCKED  = 'blocked';
    public const STATE_CONFLICT = 'conflict';

    private const OPEN_STATES = ['pending', 'retrying', 'failed', 'blocked'];

    // ─── Recording edits (called from controllers) ────────────────────────────

    /**
     * Values of the pushable fields before an edit; pass the result to track() after saving.
     *
     * @return array<string, mixed>
     */
    public function snapshot(Order $order): array
    {
        $snap = [];
        foreach (SrOrderMapper::FIELDS as $field) {
            $snap[$field] = SrOrderMapper::crmValue($order, $field);
        }

        return $snap;
    }

    /**
     * Record what changed and schedule the push. Never throws: the CRM edit is already saved.
     *
     * @param  array<string, mixed>  $before  snapshot() taken before the edit
     * @return array{level: string, message: ?string, queued: bool}
     */
    public function track(Order $order, array $before, ?int $userId = null): array
    {
        $none = ['level' => 'none', 'message' => null, 'queued' => false];

        try {
            return $this->doTrack($order, $before, $userId) ?? $none;
        } catch (\Throwable $e) {
            Log::error('SrPushService::track failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);

            return $none;
        }
    }

    private function doTrack(Order $order, array $before, ?int $userId): ?array
    {
        if (!$order->external_id) {
            return null;
        }

        $tenantId = (int) $order->tenant_id;
        $tenant   = Tenant::find($tenantId);

        if (!$tenant || !$tenant->isStore() || $tenant->isReadOnly()) {
            return null;
        }

        if (SrHealthService::setting($tenantId, 'sr_enabled') !== '1'
            || SrHealthService::setting($tenantId, 'sr_push_enabled') !== '1') {
            return null;
        }

        $preShipment = Order::preShipmentStatuses();
        if (!in_array($before['status'] ?? null, $preShipment, true) && !in_array($order->status, $preShipment, true)) {
            return null;
        }

        $pushCart = SrHealthService::setting($tenantId, 'sr_push_cart') === '1';
        $pending  = $order->sr_pending_changes ?: [];
        $notes    = [];

        foreach (SrOrderMapper::FIELDS as $field) {
            $now = SrOrderMapper::crmValue($order, $field);

            if (SrOrderMapper::equal($field, $before[$field] ?? null, $now)) {
                continue;
            }

            if ($field === 'status' && SrStatusMap::toSr($now) === null) {
                unset($pending['status']);
                $notes[] = 'Статус «' . $now . '» в SalesRender не передаётся — в колл-центре заявка остаётся в прежнем статусе.';
                continue;
            }

            if ($field === 'cart' && !$pushCart) {
                $notes[] = 'Изменения корзины не отправляются в SalesRender (отправка корзины выключена в настройках).';
                continue;
            }

            $entry = $pending[$field] ?? ['from' => $before[$field] ?? null];
            $entry['to']      = $now;
            $entry['at']      = now()->toIso8601String();
            $entry['user_id'] = $userId;

            if (SrOrderMapper::equal($field, $entry['from'], $now)) {
                unset($pending[$field]); // the user reverted the edit before it was sent
            } else {
                $pending[$field] = $entry;
            }
        }

        $state = $order->sr_sync_state;

        if ($pending === []) {
            if (in_array($state, self::OPEN_STATES, true)) {
                $state = self::STATE_SYNCED;
            }
            $this->save($order, ['sr_pending_changes' => null, 'sr_sync_state' => $state]);

            return $notes ? ['level' => 'info', 'message' => implode(' ', $notes), 'queued' => false] : null;
        }

        if ($state === self::STATE_CONFLICT) {
            $this->save($order, ['sr_pending_changes' => $pending]);

            return [
                'level' => 'warning', 'queued' => false,
                'message' => 'Заявка в конфликте с SalesRender: изменения не отправлены. Откройте заказ и выберите, какое значение оставить.',
            ];
        }

        $block = SrHealthService::blockingFor($tenantId, 'updateOrder');
        if ($block) {
            $this->save($order, [
                'sr_pending_changes'    => $pending,
                'sr_sync_state'         => self::STATE_BLOCKED,
                'sr_last_error_code'    => $block['code'],
                'sr_last_error_message' => SrResult::clip($block['message'], 500),
            ]);

            return [
                'level' => 'warning', 'queued' => false,
                'message' => 'Сохранено в CRM, но не отправлено в колл-центр: ' . $block['message'],
            ];
        }

        $this->save($order, ['sr_pending_changes' => $pending, 'sr_sync_state' => self::STATE_PENDING]);

        PushOrderToSalesRenderJob::dispatch($order->id)->delay(now()->addSeconds((int) config('salesrender.push_delay_seconds', 15)));

        array_unshift($notes, 'Изменения отправляются в SalesRender.');

        return ['level' => 'info', 'message' => implode(' ', $notes), 'queued' => true];
    }

    // ─── Push (queue job) ─────────────────────────────────────────────────────

    /**
     * Run inside Cache::lock('sr-order:{id}').
     *
     * @param  bool  $force         push pending edits even when SalesRender changed them (user chose "keep mine")
     * @param  bool  $ignoreHealth  user pressed "retry": try the request even if health says blocked
     */
    public function push(Order $order, bool $force = false, bool $ignoreHealth = false, ?int $userId = null): void
    {
        $order->refresh();

        $pending = $order->sr_pending_changes ?: [];
        if ($pending === [] || !$order->external_id) {
            return;
        }
        if ($order->sr_sync_state === self::STATE_CONFLICT && !$force) {
            return;
        }

        $tenantId = (int) $order->tenant_id;
        $started  = microtime(true);

        if (SrHealthService::setting($tenantId, 'sr_enabled') !== '1'
            || SrHealthService::setting($tenantId, 'sr_push_enabled') !== '1') {
            return; // switched off: keep the pending edits untouched
        }

        $token   = SrHealthService::setting($tenantId, 'api_token_call_centr');
        $company = SrHealthService::setting($tenantId, 'company_id_in_call_centre');
        $project = SrHealthService::setting($tenantId, 'project_id_in_call_centr');

        if ($token === '' || $company === '') {
            $this->fail($order, SrResult::failure(SrResult::CONFIG_INCOMPLETE), array_keys($pending), $started, $userId);

            return;
        }

        if (!$ignoreHealth && ($block = SrHealthService::blockingFor($tenantId, 'updateOrder'))) {
            $this->save($order, [
                'sr_sync_state'         => self::STATE_BLOCKED,
                'sr_last_error_code'    => $block['code'],
                'sr_last_error_message' => SrResult::clip($block['message'], 500),
            ]);
            SrHealthService::flush($tenantId);

            return;
        }

        $service = new SalesRenderService($token, $company, $project, $tenantId);
        $fetch   = $service->fetchOrderResult((string) $order->external_id);

        if (!$fetch->ok) {
            $this->fail($order, $fetch, array_keys($pending), $started, $userId);

            return;
        }

        $sr = $fetch->data['order'];

        // Compare every pending edit with what SalesRender holds now.
        $toPush    = [];
        $conflicts = [];
        $done      = [];

        foreach ($pending as $field => $entry) {
            $verdict = $force ? 'from' : $this->classify($field, $entry, $sr);

            if ($verdict === 'to') {
                $done[] = $field;
            } elseif ($verdict === 'conflict') {
                $conflicts[$field] = $this->srComparable($field, $sr);
            } else {
                $toPush[$field] = $entry['to'];
            }
        }

        foreach ($done as $field) {
            unset($pending[$field]);
        }
        foreach ($conflicts as $field => $srValue) {
            $pending[$field]['conflict'] = true;
            $pending[$field]['sr']       = $srValue;
        }

        if ($toPush === []) {
            $this->finalize($order, $pending, array_keys($conflicts), [], $started, $userId, 'skipped');

            return;
        }

        // Build the UpdateOrderInput from the CURRENT order values (the latest edit wins).
        // A field that cannot be sent (unlinked product, status absent in SalesRender) does not
        // block the others: it stays pending with its own error.
        $built = $this->buildInput($order, array_keys($toPush), $service, $tenantId);
        if ($built instanceof SrResult) {
            $this->fail($order, $built, array_keys($toPush), $started, $userId);

            return;
        }

        $problems = $built['errors']; // field => SrResult

        if ($built['sent'] === []) {
            $this->fail($order, reset($problems), array_keys($problems), $started, $userId);

            return;
        }

        $update = $service->updateOrder((string) $order->external_id, $built['input']);
        if (!$update->ok) {
            $this->fail($order, $update, $built['sent'], $started, $userId);

            return;
        }

        // SalesRender silently ignores unknown field ids, so confirm by reading the order back.
        $check = $service->fetchOrderResult((string) $order->external_id);
        if (!$check->ok) {
            $this->fail($order, $check, $built['sent'], $started, $userId);

            return;
        }

        $confirmed = [];
        $labels    = ['phone' => 'телефон', 'full_name' => 'ФИО', 'cart' => 'корзину', 'status' => 'статус'];
        foreach ($built['sent'] as $field) {
            if ($this->srMatchesCrm($field, $check->data['order'], $order)) {
                $confirmed[] = $field;
            } else {
                $problems[$field] = SrResult::failure(SrResult::FIELD_NOT_APPLIED, 200, $labels[$field] ?? $field);
            }
        }

        $this->finalize($order, $pending, array_keys($conflicts), $confirmed, $started, $userId, 'success', $problems);
    }

    // ─── Pull-side helpers (SyncSalesRenderJob) ───────────────────────────────

    /**
     * Compare pending edits with a freshly fetched SR order. Drops edits SalesRender already has,
     * flags independent changes as conflicts. Returns the fields the pull job must NOT overwrite.
     *
     * @return string[]
     */
    public function reconcile(Order $order, array $srOrder): array
    {
        $pending = $order->sr_pending_changes ?: [];
        if ($pending === []) {
            return [];
        }

        $conflictFound = false;
        foreach ($pending as $field => $entry) {
            $verdict = $this->classify($field, $entry, $srOrder);

            if ($verdict === 'to') {
                unset($pending[$field]);
            } elseif ($verdict === 'conflict') {
                $pending[$field]['conflict'] = true;
                $pending[$field]['sr']       = $this->srComparable($field, $srOrder);
                $conflictFound = true;
            }
        }

        $state = $order->sr_sync_state;
        if ($pending === []) {
            $state = self::STATE_SYNCED;
        } elseif ($conflictFound) {
            $state = self::STATE_CONFLICT;
        }

        $this->save($order, ['sr_pending_changes' => $pending ?: null, 'sr_sync_state' => $state]);

        return array_keys($pending);
    }

    // ─── User actions ─────────────────────────────────────────────────────────

    /** "Retry": push again right now, even if the integration is flagged as blocked. */
    public function retry(Order $order, ?int $userId = null): void
    {
        $this->save($order, [
            'sr_sync_state'    => self::STATE_PENDING,
            'sr_push_attempts' => 0,
        ]);

        PushOrderToSalesRenderJob::dispatch($order->id, false, true, $userId);
    }

    /**
     * Conflict resolution. keep_local: send the CRM values. accept_sr: take SalesRender values.
     *
     * @return SrResult|null  failure when SalesRender could not be read (accept_sr only)
     */
    public function resolve(Order $order, string $choice, ?int $userId = null): ?SrResult
    {
        $pending = $order->sr_pending_changes ?: [];

        if ($choice === 'keep_local') {
            foreach ($pending as $field => $entry) {
                unset($pending[$field]['conflict'], $pending[$field]['sr']);
            }
            $this->save($order, [
                'sr_pending_changes' => $pending ?: null,
                'sr_sync_state'      => self::STATE_PENDING,
                'sr_push_attempts'   => 0,
            ]);

            PushOrderToSalesRenderJob::dispatch($order->id, true, true, $userId);

            return null;
        }

        // accept_sr
        $tenantId = (int) $order->tenant_id;
        $service  = new SalesRenderService(
            SrHealthService::setting($tenantId, 'api_token_call_centr'),
            SrHealthService::setting($tenantId, 'company_id_in_call_centre'),
            SrHealthService::setting($tenantId, 'project_id_in_call_centr'),
            $tenantId
        );

        $fetch = $service->fetchOrderResult((string) $order->external_id);
        if (!$fetch->ok) {
            return $fetch;
        }

        $sr   = $fetch->data['order'];
        $data = [];

        foreach ($pending as $field => $entry) {
            if (empty($entry['conflict'])) {
                continue;
            }

            if ($field === 'phone') {
                $phone = PhoneNormalizer::normalize(SrOrderMapper::srValue($sr, 'phone'));
                if ($phone) {
                    $data['phone'] = $phone;
                }
            } elseif ($field === 'full_name') {
                $name = SrOrderMapper::srValue($sr, 'full_name');
                if ($name !== '') {
                    $data['full_name'] = $name;
                }
            } elseif ($field === 'cart') {
                $data = array_merge($data, $this->cartAttributes($order, SrOrderMapper::srCart($sr)));
            } elseif ($field === 'status') {
                $crm = SrStatusMap::toCrm(SrOrderMapper::srValue($sr, 'status'));
                if ($crm) {
                    $data['status'] = $crm;
                    if ($crm === 'Дубль') {
                        $data['funnel_exclude'] = true;
                    }
                }
            }

            unset($pending[$field]);
        }

        $data['last_updated_by_user_id'] = $userId;
        $order->update($data); // regular edit (observer: customer base, stock); never schedules a push

        $remaining = $pending ?: null;
        $this->save($order, [
            'sr_pending_changes' => $remaining,
            'sr_sync_state'      => $remaining ? self::STATE_PENDING : self::STATE_SYNCED,
            'sr_last_synced_at'  => now(),
        ]);

        if ($remaining) {
            PushOrderToSalesRenderJob::dispatch($order->id, false, true, $userId);
        }

        return null;
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    /**
     * @return string  'to' | 'from' | 'conflict'
     */
    private function classify(string $field, array $entry, array $srOrder): string
    {
        if ($field === 'status') {
            $target = SrStatusMap::toSr($entry['to'] ?? null);
            $srName = SrOrderMapper::srValue($srOrder, 'status');

            if ($target !== null && $srName === $target) {
                return 'to';
            }

            // SalesRender already holds another closing status chosen by the call center.
            return in_array($srName, SrStatusMap::srFinalNames(), true) ? 'conflict' : 'from';
        }

        $srValue = SrOrderMapper::srValue($srOrder, $field);

        if (SrOrderMapper::equal($field, $srValue, $entry['to'] ?? null)) {
            return 'to';
        }

        // Nothing stored in SalesRender (field absent in the project, or never filled): not an
        // independent change by the call center — send it; read-back reports it if it is ignored.
        if ($srValue === '' || $srValue === []) {
            return 'from';
        }

        if (SrOrderMapper::equal($field, $srValue, $entry['from'] ?? null)) {
            return 'from';
        }

        return 'conflict';
    }

    /** SalesRender value in the same shape as pending from/to, for the conflict dialog. */
    private function srComparable(string $field, array $srOrder)
    {
        if ($field === 'status') {
            $name = SrOrderMapper::srValue($srOrder, 'status');

            return SrStatusMap::toCrm($name) ?? $name;
        }

        return SrOrderMapper::srValue($srOrder, $field);
    }

    private function srMatchesCrm(string $field, array $srOrder, Order $order): bool
    {
        if ($field === 'status') {
            $target = SrStatusMap::toSr((string) $order->status);

            return $target !== null && SrOrderMapper::srValue($srOrder, 'status') === $target;
        }

        return SrOrderMapper::equal($field, SrOrderMapper::srValue($srOrder, $field), SrOrderMapper::crmValue($order, $field));
    }

    /**
     * @param  string[]  $fields
     * @return array{input: array<string, mixed>, sent: string[], errors: array<string, SrResult>}|SrResult
     *         SrResult when nothing can be decided (SalesRender unreachable / no access)
     */
    private function buildInput(Order $order, array $fields, SalesRenderService $service, int $tenantId)
    {
        $ids    = $this->fieldIds($tenantId);
        $input  = [];
        $data   = [];
        $sent   = [];
        $errors = [];

        if (in_array('phone', $fields, true)) {
            $data['phoneFields'] = [['field' => $ids['phone'], 'value' => PhoneNormalizer::toInternationalDigits($order->phone)]];
            $sent[] = 'phone';
        }

        if (in_array('full_name', $fields, true)) {
            $data['humanNameFields'] = [['field' => $ids['name'], 'value' => ['lastName' => SrOrderMapper::collapse((string) $order->full_name)]]];
            $sent[] = 'full_name';
        }

        if ($data !== []) {
            $input['orderData'] = $data;
        }

        if (in_array('cart', $fields, true)) {
            $items = [];
            foreach (array_values($order->goods ?? []) as $i => $name) {
                $product = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('name', $name)
                    ->first();

                if (!$product || !$product->sr_item_id) {
                    $errors['cart'] = SrResult::failure(SrResult::CART_ITEM_UNMAPPED, null, (string) $name);
                    $items = null;
                    break;
                }

                $items[] = [
                    'itemId'    => (int) $product->sr_item_id,
                    'variation' => (int) (($order->variationAt($i)['number'] ?? 0) ?: 1),
                    'quantity'  => (int) (($order->quantities ?? [])[$i] ?? 1),
                    'pricing'   => ['unitPrice' => (float) (($order->prices ?? [])[$i] ?? 0)],
                ];
            }

            if ($items !== null) {
                $input['cart'] = ['items' => $items];
                $sent[]        = 'cart';
            }
        }

        if (in_array('status', $fields, true)) {
            $srName   = SrStatusMap::toSr((string) $order->status);
            $statuses = $service->fetchStatuses();

            if ($statuses instanceof SrResult) {
                return $statuses; // cannot even read the status list: retry / block as a whole
            }

            if ($srName === null || !isset($statuses[$srName])) {
                $errors['status'] = SrResult::failure(SrResult::STATUS_NOT_FOUND_IN_SR, null, (string) $srName);
            } else {
                $input['statusId'] = $statuses[$srName];
                $sent[]            = 'status';
            }
        }

        return ['input' => $input, 'sent' => $sent, 'errors' => $errors];
    }

    /** @return array{phone: string, name: string} */
    private function fieldIds(int $tenantId): array
    {
        $map = json_decode(SrHealthService::setting($tenantId, 'sr_field_map'), true);

        return [
            'phone' => is_array($map) && !empty($map['phone_field_id']) ? (string) $map['phone_field_id'] : 'phone',
            'name'  => is_array($map) && !empty($map['name_field_id']) ? (string) $map['name_field_id'] : 'name',
        ];
    }

    /**
     * Local attributes for a cart taken from SalesRender lines.
     *
     * @param  list<array{name:string, qty:int, price:float, var:?int}>  $lines
     * @return array<string, mixed>
     */
    private function cartAttributes(Order $order, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $variations = [];
        $any        = false;
        $service    = app(ProductVariationService::class);

        foreach ($lines as $line) {
            $found = null;
            if ($line['var']) {
                $product   = Product::withoutGlobalScopes()->where('tenant_id', $order->tenant_id)->where('name', $line['name'])->first();
                $variation = $product ? $service->find($product, (int) $line['var']) : null;
                if ($variation) {
                    $found = ['number' => $variation->number, 'name' => $variation->name];
                    $any   = true;
                }
            }
            $variations[] = $found;
        }

        return [
            'goods'      => array_column($lines, 'name'),
            'quantities' => array_column($lines, 'qty'),
            'prices'     => array_column($lines, 'price'),
            'variations' => $any ? $variations : null,
        ];
    }

    /**
     * Success path: drop pushed / already equal edits and set the final state.
     *
     * @param  array<string, array>  $pending  pending edits after dropping `done` and flagging conflicts
     * @param  string[]              $conflictFields
     * @param  string[]              $pushedFields
     * @param  array<string, SrResult> $problems  fields that could not be delivered (they stay pending)
     */
    private function finalize(Order $order, array $pending, array $conflictFields, array $pushedFields, float $started, ?int $userId, string $logStatus, array $problems = []): void
    {
        // The user may have edited again while the request was in flight: keep newer edits.
        $order->refresh();
        $fresh = $order->sr_pending_changes ?: [];

        foreach ($pushedFields as $field) {
            if (isset($fresh[$field]) && SrOrderMapper::equal($field, $fresh[$field]['to'] ?? null, SrOrderMapper::crmValue($order, $field))) {
                unset($fresh[$field]);
            }
        }
        foreach ($fresh as $field => $entry) {
            if (!isset($pending[$field])) {
                unset($fresh[$field]); // dropped during comparison (SalesRender already had it)
            }
        }
        foreach ($conflictFields as $field) {
            if (isset($fresh[$field])) {
                $fresh[$field]['conflict'] = true;
                $fresh[$field]['sr']       = $pending[$field]['sr'] ?? null;
            }
        }

        $hasConflict = false;
        foreach ($fresh as $entry) {
            if (!empty($entry['conflict'])) {
                $hasConflict = true;
            }
        }

        $attrs = [
            'sr_pending_changes'    => $fresh ?: null,
            'sr_last_error_code'   => null,
            'sr_last_error_message' => null,
            'sr_last_attempt_at'   => now(),
            'sr_push_attempts'     => 0,
        ];

        if ($pushedFields !== []) {
            $attrs['sr_last_synced_at'] = now();
        }

        $firstProblem = $problems ? reset($problems) : null;

        if ($firstProblem) {
            // Some fields were delivered, others could not be: show the reason, do not loop.
            $attrs['sr_sync_state']         = self::STATE_FAILED;
            $attrs['sr_last_error_code']    = $firstProblem->errorCode;
            $attrs['sr_last_error_message'] = SrResult::clip($firstProblem->message, 500);
        } elseif ($hasConflict) {
            $attrs['sr_sync_state'] = self::STATE_CONFLICT;
        } elseif ($fresh !== []) {
            $attrs['sr_sync_state'] = self::STATE_PENDING;
        } else {
            $attrs['sr_sync_state'] = self::STATE_SYNCED;
        }

        $this->save($order, $attrs);
        $this->logPush($order, $firstProblem ? 'failed' : ($hasConflict && $pushedFields === [] ? 'conflict' : $logStatus), array_merge($pushedFields, $conflictFields, array_keys($problems)), $firstProblem, $started, $userId);

        if (!$hasConflict && !$firstProblem && $fresh !== []) {
            PushOrderToSalesRenderJob::dispatch($order->id)->delay(now()->addSeconds((int) config('salesrender.push_delay_seconds', 15)));
        }

        SrHealthService::flush((int) $order->tenant_id);
    }

    /**
     * @param  string[]  $fields
     */
    private function fail(Order $order, SrResult $result, array $fields, float $started, ?int $userId): void
    {
        $attempts = (int) $order->sr_push_attempts;
        $delays   = (array) config('salesrender.backoff', [60, 300, 900, 3600]);
        $retryIn  = null;

        if ($result->isTransient()) {
            $attempts++;
            if ($attempts <= count($delays)) {
                $state   = self::STATE_RETRYING;
                $retryIn = (int) $delays[$attempts - 1];
            } else {
                $state = self::STATE_FAILED;
            }
        } elseif ($result->isAccessProblem()) {
            $state = self::STATE_BLOCKED;
        } else {
            $state = self::STATE_FAILED;
        }

        $this->save($order, [
            'sr_sync_state'         => $state,
            'sr_last_error_code'    => $result->errorCode,
            'sr_last_error_message' => SrResult::clip($result->message, 500),
            'sr_last_attempt_at'    => now(),
            'sr_push_attempts'      => $attempts,
        ]);

        $this->logPush($order, 'failed', $fields, $result, $started, $userId);

        if ($retryIn !== null) {
            PushOrderToSalesRenderJob::dispatch($order->id, false, true)->delay(now()->addSeconds($retryIn));
        }

        SrHealthService::flush((int) $order->tenant_id);
    }

    /**
     * @param  string[]  $fields
     */
    private function logPush(Order $order, string $status, array $fields, ?SrResult $result, float $started, ?int $userId): void
    {
        try {
            DB::table('order_sr_pushes')->insert([
                'tenant_id'   => $order->tenant_id,
                'order_id'    => $order->id,
                'user_id'     => $userId,
                'fields'      => json_encode(array_values($fields)),
                'status'      => $status,
                'error_code'  => $result ? $result->errorCode : null,
                'http_status' => $result ? $result->httpStatus : null,
                'message'     => $result ? SrResult::clip($result->message, 500) : null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SrPushService: push log failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Write SR columns without model events (they are bookkeeping, not an order edit).
     *
     * @param  array<string, mixed>  $attrs
     */
    private function save(Order $order, array $attrs): void
    {
        $order->forceFill($attrs)->saveQuietly();
        SrHealthService::flush((int) $order->tenant_id);
    }
}
