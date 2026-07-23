<?php

namespace App\Http\Controllers;

use App\Models\TenantSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingController extends Controller
{
    public function __construct()
    {
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
            'belpost' => [
                'label' => 'Белпочта',
                'keys'  => [
                    'auth_token_bp'        => ['Токен авторизации (Bearer)',    'password', 'Bearer …',        ''],
                    'elc'                  => ['ELC (код отправителя)',          'text',     '…',               ''],
                    'belpost_sender_email' => ['Email отправителя (ecommerce)', 'text',     'shop@example.by', 'Уведомление о выдаче; обязателен для ecommerce-типов'],
                    'shelf_life'           => ['Срок хранения в ПВЗ, дней',    'text',     '10',              'По умолчанию 10 дней'],
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
                    'token_sms_by' => ['API-токен',     'password', '…', ''],
                    'alphaname_id' => ['ID альфаимени', 'text',     '…', ''],
                ],
            ],
            'blacklist' => [
                'label' => 'Blacks.by',
                'keys'  => [
                    'api_key_blacks_by' => ['API-ключ', 'password', '…', ''],
                ],
            ],
            'system' => [
                'label' => 'Системные',
                'keys'  => [
                    'webhook_secret' => ['Webhook-секрет', 'password', '(авто)', 'Вставьте в заголовок X-Webhook-Token на лендинге при POST на /api/webhook/lead'],
                ],
            ],
        ];
    }

    /** Flat list of all known setting keys. */
    protected static function allKeys(): array
    {
        $keys = [];
        foreach (static::schema() as $group) {
            foreach ($group['keys'] as $key => $meta) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** Flat list of keys whose type is 'toggle' (must always be persisted). */
    protected static function toggleKeys(): array
    {
        $keys = [];
        foreach (static::schema() as $group) {
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
        $canManage = Gate::check('manage-settings');
        $tenantId  = Auth::user()->tenant_id;

        $stored = $canManage
            ? TenantSetting::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->pluck('value', 'key')
                ->toArray()
            : [];

        return Inertia::render('Settings/Index', [
            'schema'              => $canManage ? static::schema() : [],
            'current'             => $stored,
            'canManageSettings'   => $canManage,
            'theme'               => Auth::user()->theme ?? 'system',
        ]);
    }

    // ─── Save ─────────────────────────────────────────────────────────────────

    /**
     * POST /settings
     * Saves { settings: { key: value } } for the current tenant.
     *
     * Toggle fields ('1' / '') are always saved so the user can explicitly disable them.
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
        $input    = $request->input('settings', []);
        $allowed  = static::allKeys();
        $toggles  = static::toggleKeys();

        foreach ($allowed as $key) {
            $value = isset($input[$key]) ? trim((string)$input[$key]) : '';

            if (in_array($key, $toggles, true)) {
                // Always persist toggles (empty string = disabled)
                TenantSetting::put($tenantId, $key, $value);
            } elseif ($value !== '') {
                TenantSetting::put($tenantId, $key, $value);
            }
        }

        return back()->with('message', 'Настройки сохранены.');
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
