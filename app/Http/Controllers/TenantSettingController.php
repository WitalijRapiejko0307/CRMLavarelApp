<?php

namespace App\Http\Controllers;

use App\Exceptions\TelegramException;
use App\Jobs\SendDailyDigestJob;
use App\Models\TenantSetting;
use App\Support\TenantOrderListColumns;
use App\Services\ConnectionService;
use App\Services\EvropostStoreSearchService;
use App\Services\SmsService;
use App\Services\SrConnectionCheckService;
use App\Services\SrHealthService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService
    ) {
        $this->middleware(['auth', 'tenant', 'tenant.writable']);
    }

    /**
     * Settings schema.
     *
     * Format: group => [label, keys => [key => meta]]
     * meta = [label, type, placeholder, hint, options_or_null, depends_on_or_null]
     *   meta[4] — ['value' => 'Label'] for type='select'
     *   meta[5] — ['key' => 'value'] visibility condition, e.g. ['ep_api_version' => 'legacy']
     *
     * Old 4-element arrays remain valid (meta[4] and meta[5] default to null).
     */
    protected static function schema(): array
    {
        return [
            'shop' => [
                'label' => 'Магазин',
                'keys'  => [
                    'shop_name' => ['Название магазина', 'text', 'BaseCRM', 'Отображается в шапке сайта'],
                    'default_courier' => ['Служба доставки по умолчанию', 'select', '', 'Подставляется в новые заявки, если служба не указана. Европочта сработает, когда подключён её API', ['belpost' => 'Белпочта', 'europochta' => 'Европочта']],
                ],
            ],
            'cc' => [
                'label' => 'Скрипт',
                'keys'  => [
                    'call_script' => [
                        'Скрипт звонка',
                        'textarea',
                        'Здравствуйте, {name}! По поводу {tovar} на сумму {sum} р.',
                        'Плейсхолдеры: {name} {tovar} {sum}',
                    ],
                    'cc_round_robin' => [
                        'Распределять новые лиды',
                        'toggle',
                        '',
                        'Оператор видит только свои; admin и manager — все',
                    ],
                ],
            ],
            'belpost' => [
                'label' => 'Белпочта',
                'keys'  => [
                    'auth_token_bp'        => ['Токен авторизации (Bearer)',    'password', 'Bearer …',        ''],
                    'elc'                  => ['ЭЛС (электронный лицевой счёт)', 'text', '…', 'Номер электронного лицевого счёта из кабинета Белпочты'],
                    'belpost_sender_email'    => ['Email отправителя (ecommerce)', 'text', 'shop@example.by', 'Уведомление о выдаче; обязателен для ecommerce-типов'],
                    'belpost_sender_name'     => ['Имя / ИП отправителя', 'text', '', 'Печатается на бланке слева'],
                    'belpost_sender_street'   => ['Улица, дом, кв. отправителя', 'text', '', ''],
                    'belpost_sender_postcode' => ['Индекс отправителя', 'text', '', ''],
                    'belpost_sender_city'     => ['Город отправителя', 'text', '', ''],
                    'belpost_contract_no'     => ['№ договора', 'text', '', 'Строка «Оплачено по договору» на бланке'],
                    'belpost_contract_date'   => ['Дата договора', 'text', '', ''],
                    'shelf_life'              => ['Срок хранения в ПВЗ, дней', 'text', '10', 'Количество дней хранения в отделении; по умолчанию 10'],
                    'belpost_label_size'   => ['Размер бланка по умолчанию',   'select',   '', '', ['210x150' => '210×150', '150x100' => '150×100', '120x80' => '120×80']],
                ],
            ],
            'europochta' => [
                'label' => 'Европочта',
                'keys'  => [
                    'ep_api_version'    => ['Версия API',    'select',   '', 'Выберите версию Европочты', ['new' => 'v1.8.2 (актуальный)', 'legacy' => 'JWT (устаревший)']],
                    'warehouse_id_start'=> ['ОПС отправки', 'ops_picker', '', 'Отделение, из которого отправляются посылки. Можно заменить в конкретной заявке'],
                    'warehouse_label'   => ['Название ОПС отправки', 'hidden', '', ''],
                    'token_ep'          => ['Bearer-токен API v1.8.2', 'password', '…', '', null, ['ep_api_version' => 'new']],
                    'contractor_unn'    => ['УНН контрагента',         'text',     '…', '', null, ['ep_api_version' => 'new']],
                    'login_name_ep'     => ['Логин JWT API',           'text',     '…', '', null, ['ep_api_version' => 'legacy']],
                    'password_ep'       => ['Пароль JWT API',          'password', '…', '', null, ['ep_api_version' => 'legacy']],
                    'service_number_ep' => ['UUID сервиса',            'password', '…', '', null, ['ep_api_version' => 'legacy']],
                ],
            ],
            'salesrender' => [
                'label' => 'SalesRender (CallCentr)',
                'keys'  => [
                    'sr_enabled'               => ['Включить интеграцию с колл-центром', 'toggle',   '', ''],
                    'api_token_call_centr'     => ['API-токен SalesRender',              'password', '…', ''],
                    'company_id_in_call_centre'=> ['Company ID (в URL)',                 'text',     '…', 'Числовой ID компании в SalesRender'],
                    'project_id_in_call_centr' => ['Project UUID (GraphQL)',             'text',     '…', ''],
                    'sr_push_enabled'          => ['Отправлять изменения заказов в колл-центр', 'toggle', '', 'Телефон, ФИО и статус (Заказать, Отказ(Ошибка), Дубль, Спам) изменённых заказов уходят в SalesRender. Включается после успешной «Проверки подключения»'],
                    'sr_push_cart'             => ['Отправлять изменения корзины', 'toggle', '', 'Товары, количество и цены (в рублях). Товар должен быть связан с позицией SalesRender (ID позиции в карточке товара), а цена — в допустимом диапазоне товара в SalesRender, иначе SalesRender отклонит изменение'],
                ],
            ],
            'sms' => [
                'label' => 'SMS.by',
                'keys'  => [
                    'token_sms_by'       => ['API-токен',     'password', '…', ''],
                    'alphaname_id'       => ['ID альфаимени', 'text',     '…', ''],
                    'sms_rules'          => ['Когда отправлять SMS', 'custom', '', 'Склеивается из тогглов при сохранении'],
                    'sms_reminder_day_1' => ['Первый день напоминания', 'text', '5', 'День после прибытия в отделение'],
                    'sms_reminder_day_2' => ['Второй день напоминания', 'text', '10', 'День после прибытия в отделение'],
                    'sms_tpl_shipped'    => ['Текст при отправке', 'textarea', SmsService::DEFAULT_TPL_SHIPPED, 'Плейсхолдеры: {name} {track} {tovar}'],
                    'sms_tpl_arrived'    => ['Текст в отделении', 'textarea', SmsService::DEFAULT_TPL_ARRIVED, 'Плейсхолдеры: {name} {track} {tovar}'],
                    'sms_tpl_reminder'   => ['Текст напоминания', 'textarea', SmsService::DEFAULT_TPL_REMINDER, 'Плейсхолдеры: {name} {track} {tovar} {days}'],
                ],
            ],
            'telegram' => [
                'label' => 'Telegram',
                'keys'  => [
                    'telegram_bot_token' => ['Токен бота', 'password', '…', 'Токен из @BotFather'],
                    'telegram_chat_id'   => ['Chat ID', 'text', '…', 'Куда слать PDF и сводку'],
                ],
            ],
            'digest' => [
                'label' => 'Ежедневная сводка',
                'keys'  => [
                    'digest_enabled' => [
                        'Ежедневная сводка',
                        'toggle',
                        '',
                        'Заявки, принятые, продажи и топ товаров за сегодня',
                    ],
                    'digest_time' => [
                        'Время отправки',
                        'text',
                        '21:00',
                        'Часовой пояс Europe/Minsk, формат ЧЧ:ММ',
                    ],
                    'digest_email' => [
                        'Email для сводки',
                        'text',
                        'owner@example.com',
                        'Пусто — не отправлять письмо. Telegram — из настроек бота выше',
                    ],
                ],
            ],
            'system' => [
                'label' => 'Заявки с сайта',
                'keys'  => [
                    'webhook_secret' => ['Webhook-секрет', 'password', '(авто)', 'Передайте секрет в заголовке X-Webhook-Token на лендинге'],
                ],
            ],
        ];
    }

    /** Flat list of all known setting keys. */
    protected static function allKeys(): array
    {
        return static::keysFromSchema(static::schema());
    }

    protected static function keysForTenant($tenant): array
    {
        return static::keysFromSchema(static::schemaForTenant($tenant));
    }

    protected static function keysFromSchema(array $schema): array
    {
        $keys = [];
        foreach ($schema as $group) {
            foreach ($group['keys'] as $key => $meta) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Flat list of keys whose type is 'toggle' (must always be persisted). */
    protected static function toggleKeys(): array
    {
        return static::toggleKeysFromSchema(static::schema());
    }

    protected static function toggleKeysForTenant($tenant): array
    {
        return static::toggleKeysFromSchema(static::schemaForTenant($tenant));
    }

    protected static function toggleKeysFromSchema(array $schema): array
    {
        $keys = [];
        foreach ($schema as $group) {
            foreach ($group['keys'] as $key => $meta) {
                if (($meta[1] ?? '') === 'toggle') {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    // ─── Page ────────────────────────────────────────────────────────────────

    /**
     * GET /settings
     */
    public function index(): Response
    {
        $canView = Gate::check('view-settings');
        $canEdit = Gate::check('manage-settings');
        $tenant  = Auth::user()->tenant;
        $tenantId  = Auth::user()->tenant_id;

        $stored = $canView
            ? TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->pluck('value', 'key')
                ->toArray()
            : [];

        [$current, $secretPreviews] = $canView
            ? static::buildCurrentForUi($stored, $tenant)
            : [[], []];

        $connectionData = $canView
            ? $this->connectionService->connectionsForSettings($tenant)
            : [];

        return Inertia::render('Settings/Index', [
            'schema'           => $canView ? static::schemaForTenant($tenant) : [],
            'current'          => $current,
            'secretPreviews'   => $secretPreviews,
            'canViewSettings'  => $canView,
            'canEditSettings'  => $canEdit,
            'theme'            => Auth::user()->theme ?? 'system',
            'connectionData'   => $connectionData,
            'webhook_url'      => $canView ? static::webhookUrl() : null,
            'srPanel'          => $canView && $tenant->isStore() ? $this->srPanel((int) $tenantId) : null,
            'orderListColumns' => $canView && $tenant->isStore()
                ? TenantOrderListColumns::fieldsForTenant($tenantId)
                : [],
            'orderColumnOptions' => $canView && $tenant->isStore()
                ? TenantOrderListColumns::optionsForUi()
                : [],
        ]);
    }

    /**
     * SalesRender state block of the settings page.
     *
     * @return array<string, mixed>
     */
    protected function srPanel(int $tenantId): array
    {
        return [
            'health'        => SrHealthService::get($tenantId),
            'checked_at'    => SrHealthService::setting($tenantId, 'sr_check_ok_at') ?: null,
            'request_text'  => SrHealthService::get($tenantId)['state'] !== SrHealthService::STATE_OK
                ? SrHealthService::requestText($tenantId)
                : null,
            'undelivered'   => \App\Models\Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('sr_sync_state', ['blocked', 'failed', 'retrying'])
                ->count(),
        ];
    }

    public static function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/webhook/lead';
    }

    /**
     * Mask a secret for UI preview: first N chars + dots. Never send full value to browser.
     */
    protected static function maskSecret(string $value, int $visible = 4): string
    {
        if ($value === '') {
            return '';
        }

        $prefix = mb_substr($value, 0, $visible);

        return $prefix . '••••••••';
    }

    protected static function schemaForTenant($tenant): array
    {
        $schema = static::schema();

        if ($tenant->isCallCenter()) {
            return array_intersect_key($schema, array_flip(['shop', 'cc']));
        }

        unset($schema['cc']);

        return $schema;
    }

    /**
     * Split stored settings into non-secret current values and masked secret previews.
     *
     * @param  array<string, string>  $stored
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected static function buildCurrentForUi(array $stored, $tenant): array
    {
        $current         = [];
        $secretPreviews  = [];

        foreach (static::schemaForTenant($tenant) as $group) {
            foreach ($group['keys'] as $key => $meta) {
                $type  = $meta[1] ?? 'text';
                $value = isset($stored[$key]) ? (string) $stored[$key] : '';

                if ($value === '') {
                    if ($key === 'sms_rules') {
                        $current[$key] = '';
                    }
                    continue;
                }

                if ($type === 'password') {
                    $secretPreviews[$key] = static::maskSecret($value);
                } else {
                    // text, select, toggle, textarea, custom
                    $current[$key] = $value;
                }
            }
        }

        return [$current, $secretPreviews];
    }

    // ─── Save ─────────────────────────────────────────────────────────────────

    /**
     * POST /settings
     * Saves { settings: { key: value } } for the current tenant.
     *
     * Toggle fields ('1' / '') are always saved so the user can explicitly disable them.
     * sms_rules is always saved (empty string = all SMS events off).
     * digest_email and digest_time are always saved so the admin can clear them.
     * Other fields: empty strings are NOT saved (keeps existing value intact).
     */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $request->validate([
            'settings'   => ['required', 'array'],
            'settings.*' => ['nullable', 'string', 'max:20000'],
        ]);

        $tenantId = Auth::user()->tenant_id;
        $tenant   = Auth::user()->tenant;
        $input    = $request->input('settings', []);
        $allowed  = static::keysForTenant($tenant);
        $toggles  = static::toggleKeysForTenant($tenant);

        $this->assertDigestCanBeEnabled($input);

        // New token / company id: the stored health and the "connection checked" mark are stale.
        $credsChanged = false;
        foreach (['api_token_call_centr', 'company_id_in_call_centre', 'project_id_in_call_centr'] as $credKey) {
            $submitted = isset($input[$credKey]) ? trim((string) $input[$credKey]) : '';
            if ($submitted !== '' && $submitted !== SrHealthService::setting($tenantId, $credKey)) {
                $credsChanged = true;
            }
        }

        $this->assertSrPushCanBeEnabled($input, $tenantId, $credsChanged, in_array('sr_push_enabled', $allowed, true));

        foreach ($allowed as $key) {
            $value = isset($input[$key]) ? trim((string)$input[$key]) : '';

            if ($key === 'sms_rules') {
                if (array_key_exists($key, $input)) {
                    TenantSetting::put($tenantId, $key, static::normalizeSmsRules($value));
                }
            } elseif (in_array($key, $toggles, true) || $key === 'digest_email' || $key === 'digest_time') {
                // Always persist toggles and digest text fields (empty string = off / clear)
                TenantSetting::put($tenantId, $key, $value);
            } elseif ($value !== '') {
                TenantSetting::put($tenantId, $key, $value);
            }
        }

        if ($credsChanged) {
            SrHealthService::reset($tenantId);
        }

        return back()->with('message', 'Настройки сохранены.');
    }

    /**
     * Sending order changes to SalesRender requires a passed connection check for the current token.
     */
    protected function assertSrPushCanBeEnabled(array $input, int $tenantId, bool $credsChanged, bool $applicable): void
    {
        if (!$applicable || (string) ($input['sr_push_enabled'] ?? '') !== '1') {
            return;
        }

        $checked = SrHealthService::setting($tenantId, 'sr_check_ok_at') !== '' && !$credsChanged;

        if (!$checked) {
            throw ValidationException::withMessages([
                'settings.sr_push_enabled' => 'Сначала сохраните токен и нажмите «Проверить подключение»: отправка включается только после успешной проверки прав.',
            ]);
        }
    }

    /**
     * POST /settings/salesrender/check
     * Safe probes (no data changed): read right, update right, statuses, fields.
     */
    public function checkSalesrender(SrConnectionCheckService $service): JsonResponse
    {
        Gate::authorize('manage-settings');

        $tenant = Auth::user()->tenant;
        if (!$tenant || !$tenant->isStore()) {
            abort(404);
        }

        $tenantId = (int) Auth::user()->tenant_id;
        $result   = $service->run($tenantId);

        return response()->json($result + [
            'health'       => SrHealthService::get($tenantId),
            'request_text' => $result['ok'] ? null : SrHealthService::requestText($tenantId),
        ]);
    }

    /**
     * POST /settings/salesrender/retry-blocked
     * After the token is fixed: put undelivered changes back into the queue.
     */
    public function retrySalesrenderBlocked(SrConnectionCheckService $service): JsonResponse
    {
        Gate::authorize('manage-settings');

        $tenant = Auth::user()->tenant;
        if (!$tenant || !$tenant->isStore()) {
            abort(404);
        }

        return response()->json(['queued' => $service->requeueBlocked((int) Auth::user()->tenant_id)]);
    }

    /**
     * POST /settings/order-columns
     * Store admin: extra columns on the orders list.
     */
    public function updateOrderColumns(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');

        $tenant = Auth::user()->tenant;
        if (!$tenant || !$tenant->isStore()) {
            abort(404);
        }

        $validated = $request->validate([
            'fields'   => ['present', 'array'],
            'fields.*' => TenantOrderListColumns::fieldValidationRules(),
        ]);

        TenantOrderListColumns::saveForTenant(
            (int) Auth::user()->tenant_id,
            $validated['fields'] ?? [],
        );

        return back()->with('message', 'Колонки списка заказов сохранены.');
    }

    /**
     * Keep only known SMS event tokens, comma-separated, no extra spaces.
     */
    protected static function normalizeSmsRules(string $posted): string
    {
        if ($posted === '') {
            return '';
        }

        $parts = [];
        foreach (explode(',', $posted) as $token) {
            $token = trim($token);
            if ($token === 'Отправка' || $token === 'В отделении') {
                $parts[] = $token;
            } elseif (preg_match('/^Напоминание \d+ день$/u', $token) === 1) {
                $parts[] = $token;
            }
        }

        return implode(',', $parts);
    }

    /**
     * POST /settings/generate-webhook-secret
     * Generates a random webhook secret and saves it.
     */
    public function generateWebhookSecret(): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('manage-settings');

        $tenantId = Auth::user()->tenant_id;
        $secret   = bin2hex(random_bytes(24));

        TenantSetting::put($tenantId, 'webhook_secret', $secret);

        return response()->json(['success' => true, 'secret' => $secret]);
    }

    /**
     * POST /settings/europochta/refresh-stores
     * Drop the instance-wide OPS directory so the next search hits Europochta.
     */
    public function refreshEuropochtaStores(EvropostStoreSearchService $search): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('manage-settings');

        $search->forgetCache();

        return response()->json([
            'success' => true,
            'message' => 'Справочник отделений обновлён',
        ]);
    }

    /**
     * Validate digest settings before persist when the admin is turning the digest on.
     *
     * @param  array<string, mixed>  $input
     */
    protected function assertDigestCanBeEnabled(array $input): void
    {
        $enabled = array_key_exists('digest_enabled', $input)
            ? trim((string) $input['digest_enabled'])
            : '';

        if ($enabled !== '1') {
            return;
        }

        $time = array_key_exists('digest_time', $input)
            ? trim((string) $input['digest_time'])
            : trim((string) TenantSetting::get('digest_time', ''));

        $email = array_key_exists('digest_email', $input)
            ? trim((string) $input['digest_email'])
            : trim((string) TenantSetting::get('digest_email', ''));

        $chatId = array_key_exists('telegram_chat_id', $input)
            ? trim((string) $input['telegram_chat_id'])
            : trim((string) TenantSetting::get('telegram_chat_id', ''));

        $errors = [];
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $errors['settings.digest_time'] = 'Укажите время в формате ЧЧ:ММ.';
        }
        if ($email === '' && $chatId === '') {
            $errors['settings.digest_email'] = 'Укажите email или Chat ID Telegram, чтобы включить сводку.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * POST /settings/digest/send-now
     * Sends today's digest immediately (stamps last_sent so evening cron will not duplicate).
     */
    public function sendDigestNow(): JsonResponse
    {
        Gate::authorize('manage-settings');

        $tenant = Auth::user()->tenant;
        if (!$tenant || !$tenant->isStore()) {
            return response()->json([
                'success'       => false,
                'error_message' => 'Сводка доступна только магазину',
            ], 422);
        }

        $result = SendDailyDigestJob::dispatchNow((int) Auth::user()->tenant_id, true);

        if (!is_array($result) || empty($result['ok'])) {
            return response()->json([
                'success'       => false,
                'error_message' => is_array($result)
                    ? (string) ($result['error'] ?? 'Не удалось отправить сводку')
                    : 'Не удалось отправить сводку',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => (string) ($result['message'] ?? 'Сводка отправлена'),
        ]);
    }

    /**
     * POST /settings/telegram/test
     * Sends a short ping via Bot API so the admin can verify token + chat.
     */
    public function testTelegram(): JsonResponse
    {
        Gate::authorize('manage-settings');

        $service = TelegramService::forTenant((int) Auth::user()->tenant_id);
        if ($service === null) {
            return response()->json([
                'success'       => false,
                'error'         => 'config_error',
                'error_message' => 'Подключите Telegram в Настройках',
            ], 422);
        }

        try {
            $service->sendMessage('CRM: связь ок');

            return response()->json([
                'success' => true,
                'message' => 'Сообщение отправлено',
            ]);
        } catch (TelegramException $e) {
            return response()->json([
                'success'       => false,
                'error'         => 'telegram_error',
                'error_message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * POST /settings/reveal-webhook-secret
     * Returns the current decrypted webhook secret. Admin only; allowed on expired trial.
     */
    public function revealWebhookSecret(): JsonResponse
    {
        Gate::authorize('manage-settings');

        $row = TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', Auth::user()->tenant_id)
            ->where('key', 'webhook_secret')
            ->first();

        $secret = $row ? (string) $row->value : '';

        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Секрет ещё не создан'], 404);
        }

        return response()->json(['success' => true, 'secret' => $secret]);
    }

    /**
     * POST /settings/schedule-deletion
     */
    public function scheduleDeletion(): RedirectResponse
    {
        Gate::authorize('admin');

        $user = Auth::user();
        $tenant = $user->tenant;

        if (!$tenant) {
            abort(404);
        }

        $tenant->scheduleDeletion((int) $user->id);
        $tenant->refresh();

        try {
            $date = $tenant->deletion_scheduled_for?->timezone(config('app.timezone'))->format('d.m.Y');
            $body = "Вы запланировали удаление компании «{$tenant->name}». "
                . "Данные будут безвозвратно удалены {$date}.";

            Mail::raw($body, function ($message) use ($user): void {
                $message->to($user->email)->subject('Запланировано удаление компании');
            });
        } catch (\Throwable) {
            // still saved
        }

        return back()->with('message', 'Удаление компании запланировано.');
    }

    /**
     * POST /settings/cancel-deletion
     */
    public function cancelDeletion(): RedirectResponse
    {
        Gate::authorize('admin');

        $tenant = Auth::user()->tenant;

        if (!$tenant) {
            abort(404);
        }

        $tenant->cancelDeletion();

        return back()->with('message', 'Запланированное удаление отменено.');
    }

    /**
     * PATCH /settings/theme
     * Saves user theme preference (all roles).
     */
    public function updateTheme(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:light,dark,system'],
        ]);

        $user        = Auth::user();
        $user->theme = $validated['theme'];
        $user->save();

        return response()->json(['theme' => $user->theme]);
    }
}
