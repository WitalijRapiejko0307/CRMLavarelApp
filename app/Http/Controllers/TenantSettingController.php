<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use App\Services\ConnectionService;
use App\Services\SmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
                    'belpost_sender_email' => ['Email отправителя (ecommerce)', 'text', 'shop@example.by', 'Уведомление о выдаче; обязателен для ecommerce-типов'],
                    'shelf_life'           => ['Срок хранения в ПВЗ, дней', 'text', '10', 'Количество дней хранения в отделении; по умолчанию 10'],
                    'belpost_label_size'   => ['Размер бланка по умолчанию',   'select',   '', '', ['210x150' => '210×150', '150x100' => '150×100', '120x80' => '120×80']],
                ],
            ],
            'europochta' => [
                'label' => 'Европочта',
                'keys'  => [
                    'ep_api_version'    => ['Версия API',    'select',   '', 'Выберите версию Европочты', ['new' => 'v1.8.2 (актуальный)', 'legacy' => 'JWT (устаревший)']],
                    'warehouse_id_start'=> ['ОПС отправки', 'text',     '…', ''],
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
            'blacklist' => [
                'label' => 'Blacks.by',
                'keys'  => [
                    'api_key_blacks_by' => ['API-ключ', 'password', '…', ''],
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
        ]);
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

        foreach ($allowed as $key) {
            $value = isset($input[$key]) ? trim((string)$input[$key]) : '';

            if ($key === 'sms_rules') {
                if (array_key_exists($key, $input)) {
                    TenantSetting::put($tenantId, $key, static::normalizeSmsRules($value));
                }
            } elseif (in_array($key, $toggles, true)) {
                // Always persist toggles (empty string = disabled)
                TenantSetting::put($tenantId, $key, $value);
            } elseif ($value !== '') {
                TenantSetting::put($tenantId, $key, $value);
            }
        }

        return back()->with('message', 'Настройки сохранены.');
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
     * POST /settings/reveal-webhook-secret
     * Returns the current decrypted webhook secret. Admin only; allowed on expired trial.
     */
    public function revealWebhookSecret(): \Illuminate\Http\JsonResponse
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
