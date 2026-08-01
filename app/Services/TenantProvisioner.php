<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantProvisioner
{
    public function __construct(
        protected ConnectionService $connectionService
    ) {}

    /**
     * Seed default tenant_settings for a new tenant.
     */
    public function provision(Tenant $tenant, string $shopName = 'BaseCRM'): void
    {
        $settings = $tenant->isCallCenter()
            ? $this->callCenterSettings($shopName)
            : $this->storeSettings($shopName);

        foreach ($settings as $key => $value) {
            DB::table('tenant_settings')->insert([
                'tenant_id' => $tenant->id,
                'key'       => $key,
                'value'     => Crypt::encryptString($value),
            ]);
        }
    }

    protected function storeSettings(string $shopName): array
    {
        return [
            'shop_name'                  => $shopName,
            'auth_token_bp'              => '',
            'elc'                        => '',
            'belpost_sender_email'       => '',
            'shelf_life'                 => '10',
            'ep_api_version'             => 'new',
            'warehouse_id_start'         => '',
            'token_ep'                   => '',
            'contractor_unn'             => '',
            'login_name_ep'              => '',
            'password_ep'                => '',
            'service_number_ep'          => '',
            'sr_enabled'                 => '',
            'api_token_call_centr'       => '',
            'company_id_in_call_centre'  => '',
            'project_id_in_call_centr'  => '',
            'api_key_blacks_by'          => '',
            'token_sms_by'               => '',
            'alphaname_id'               => '',
            'tracking_checkpoint'        => '1',
            'webhook_secret'             => Str::random(40),
        ];
    }

    protected function callCenterSettings(string $shopName): array
    {
        return [
            'shop_name'       => $shopName,
            'connection_code' => $this->connectionService->generateConnectionCode(),
        ];
    }
}
