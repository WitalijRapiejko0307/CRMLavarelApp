<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for call-center analytics (managers + stores tabs).
 *
 * Run: php artisan db:seed --class=AnalyticsDemoSeeder
 */
class AnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $storeA = Tenant::create([
            'name'                => 'Магазин Альфа',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now()->subDays(30),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now()->subDays(30),
        ]);

        $storeB = Tenant::create([
            'name'                => 'Магазин Бета',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now()->subDays(30),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now()->subDays(30),
        ]);

        $cc = Tenant::create([
            'name'                => 'Колл-центр Демо',
            'type'                => Tenant::TYPE_CALL_CENTER,
            'created_at'          => now()->subDays(30),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now()->subDays(30),
        ]);

        foreach ([$storeA, $storeB] as $store) {
            TenantConnection::create([
                'store_tenant_id'       => $store->id,
                'call_center_tenant_id' => $cc->id,
                'status'                => TenantConnection::STATUS_ACTIVE,
                'requested_at'          => now()->subDays(20),
                'approved_at'           => now()->subDays(20),
            ]);
        }

        $admin = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Админ',
            'email'     => 'cc-admin@demo.by',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);

        $manager = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Менеджер',
            'email'     => 'cc-manager@demo.by',
            'password'  => Hash::make('password'),
            'role'      => 'manager',
        ]);

        $operator = User::create([
            'tenant_id' => $cc->id,
            'name'      => 'CC Оператор',
            'email'     => 'cc-operator@demo.by',
            'password'  => Hash::make('password'),
            'role'      => 'operator',
        ]);

        $scenarios = [
            // Store A — 3 leads
            [$storeA, 'Иванов И.И.', 'Позвонить', [
                [$operator, 'Недозвон', 5],
                [$operator, 'Подтвержден', 3],
            ]],
            [$storeA, 'Петров П.П.', 'Позвонить', [
                [$manager, 'Недозвон1', 4],
                [$manager, 'Отказ', 2],
            ]],
            [$storeA, 'Сидоров С.С.', 'Позвонить', [
                [$operator, 'Спам', 1],
            ]],
            // Store B — 2 leads
            [$storeB, 'Козлов К.К.', 'Позвонить', [
                [$manager, 'Недозвон2', 6],
                [$manager, 'Подтвержден', 4],
            ]],
            [$storeB, 'Новиков Н.Н.', 'Позвонить', [
                [$operator, 'Заказать', 2],
                [$admin, 'Подтвержден', 1],
            ]],
        ];

        foreach ($scenarios as [$store, $name, $initialStatus, $events]) {
            $order = Order::withoutGlobalScopes()->create([
                'tenant_id'             => $store->id,
                'call_center_tenant_id' => $cc->id,
                'full_name'             => $name,
                'status'                => end($events)[1],
                'created_at'            => now()->subDays(7),
                'updated_at'            => now(),
            ]);

            OrderStatusHistory::create([
                'order_id'    => $order->id,
                'from_status' => null,
                'to_status'   => $initialStatus,
                'user_id'     => null,
                'created_at'  => now()->subDays(7),
            ]);

            foreach ($events as [$user, $status, $daysAgo]) {
                OrderStatusHistory::create([
                    'order_id'    => $order->id,
                    'from_status' => $initialStatus,
                    'to_status'   => $status,
                    'user_id'     => $user->id,
                    'created_at'  => now()->subDays($daysAgo),
                ]);
                $initialStatus = $status;
            }
        }

        $this->command->info('Analytics demo seeded.');
        $this->command->info("Call center: {$cc->name} (id={$cc->id})");
        $this->command->info('CC Admin:    cc-admin@demo.by / password');
        $this->command->info('CC Manager:  cc-manager@demo.by / password');
        $this->command->info('CC Operator: cc-operator@demo.by / password');
        $this->command->info('Orders: 5 (3 in Альфа, 2 in Бета)');
        $this->command->info('Open: /analytics (login as CC user)');
    }
}
