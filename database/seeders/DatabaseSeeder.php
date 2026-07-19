<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        Tenant::truncate();
        User::truncate();
        DB::table('tenant_settings')->truncate();
        Product::truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Create test tenant
        $tenant = Tenant::create([
            'name'       => 'Тестовая компания',
            'created_at' => now(),
        ]);

        // Create admin user
        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Администратор',
            'email'     => 'admin@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        // Create manager user
        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Менеджер',
            'email'     => 'manager@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        // Seed tenant settings (empty — to be filled from Settings UI)
        $settings = [
            // Shop
            'shop_name'                  => 'BaseCRM',
            // Belpost
            'auth_token_bp'              => '',
            'elc'                        => '',
            'belpost_sender_email'       => '',
            'shelf_life'                 => '10',
            // Europochta
            'ep_api_version'             => 'new',
            'warehouse_id_start'         => '',
            // Europochta new API (v1.8.2)
            'token_ep'                   => '',
            'contractor_unn'             => '',
            // Europochta legacy JWT API
            'login_name_ep'              => '',
            'password_ep'                => '',
            'service_number_ep'          => '',
            // SalesRender (CallCentr)
            'sr_enabled'                 => '',
            'api_token_call_centr'       => '',
            'company_id_in_call_centre'  => '',
            'project_id_in_call_centr'   => '',
            // Blacklist
            'api_key_blacks_by'          => '',
            // SMS
            'token_sms_by'               => '',
            'alphaname_id'               => '',
            // System
            'tracking_checkpoint'        => '1',
            'webhook_secret'             => Str::random(40),
        ];

        foreach ($settings as $key => $value) {
            DB::table('tenant_settings')->insert([
                'tenant_id' => $tenant->id,
                'key'       => $key,
                'value'     => Crypt::encryptString($value),
            ]);
        }

        // Seed sample products
        $products = [
            ['name' => 'Товар А', 'stock' => 100, 'weight' => 0.5],
            ['name' => 'Товар Б', 'stock' => 50,  'weight' => 0.3],
            ['name' => 'Товар В', 'stock' => 200, 'weight' => 0.8],
        ];

        foreach ($products as $product) {
            Product::create(array_merge($product, ['tenant_id' => $tenant->id]));
        }

        $this->command->info("Tenant: {$tenant->name} (id={$tenant->id})");
        $this->command->info('Admin: admin@crm.by / password');
        $this->command->info('Manager: manager@crm.by / password');
    }
}
