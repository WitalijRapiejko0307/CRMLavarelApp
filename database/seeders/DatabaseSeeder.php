<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

        $tenant = Tenant::create([
            'name'                => 'Тестовая компания',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Администратор',
            'email'     => 'admin@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Менеджер',
            'email'     => 'manager@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        app(TenantProvisioner::class)->provision($tenant, 'BaseCRM');

        User::create([
            'tenant_id' => null,
            'name'      => 'Super Admin',
            'email'     => 'super@crm.by',
            'password'  => Hash::make('password'),
            'role'      => 'super_admin',
        ]);

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
        $this->command->info('Super-admin: super@crm.by / password');
    }
}
