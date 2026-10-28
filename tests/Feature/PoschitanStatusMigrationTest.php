<?php

namespace Tests\Feature;

use App\Models\Income;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PoschitanStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function runMigrationUp(): object
    {
        $migration = require database_path('migrations/2026_10_28_000003_replace_poschitan_status_with_zavershen.php');

        $migration->up();

        return $migration;
    }

    /**
     * @return array{
     *     tenant: Tenant,
     *     product: Product,
     *     poschitan_order_id: int,
     *     zavrshen_order_id: int,
     *     kept_history_id: int,
     *     deleted_z_to_z_id: int,
     *     income_id: int
     * }
     */
    private function seedMigrationFixture(): array
    {
        $tenant = Tenant::create([
            'name'                => 'Poschitan migration ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        $product = Product::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Крем',
            'stock'     => 7,
            'weight'    => 100,
        ]);

        $poschitanOrderId = $this->insertOrder($tenant->id, 'Посчитан');
        $zavrshenOrderId = $this->insertOrder($tenant->id, 'Завершен');

        $keptHistoryId = DB::table('order_status_history')->insertGetId([
            'order_id'     => $zavrshenOrderId,
            'from_status'  => 'Оформлен',
            'to_status'    => 'Завершен',
            'created_at'   => now(),
        ]);

        $deletedZtoZId = DB::table('order_status_history')->insertGetId([
            'order_id'     => $poschitanOrderId,
            'from_status'  => 'Завершен',
            'to_status'    => 'Завершен',
            'created_at'   => now(),
        ]);

        DB::table('order_status_history')->insert([
            [
                'order_id'    => $poschitanOrderId,
                'from_status' => 'Завершен',
                'to_status'   => 'Посчитан',
                'created_at'  => now(),
            ],
            [
                'order_id'    => $poschitanOrderId,
                'from_status' => 'Посчитан',
                'to_status'   => 'Завершен',
                'created_at'  => now(),
            ],
        ]);

        $incomeId = DB::table('income')->insertGetId([
            'tenant_id'   => $tenant->id,
            'amount'      => 42.5,
            'order_id'    => $poschitanOrderId,
            'description' => 'auto',
            'source'      => 'заказ',
            'date'        => now()->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return [
            'tenant'               => $tenant,
            'product'              => $product,
            'poschitan_order_id'   => $poschitanOrderId,
            'zavrshen_order_id'    => $zavrshenOrderId,
            'kept_history_id'      => $keptHistoryId,
            'deleted_z_to_z_id'    => $deletedZtoZId,
            'income_id'            => $incomeId,
        ];
    }

    private function insertOrder(int $tenantId, string $status): int
    {
        $id = DB::table('orders')->insertGetId([
            'tenant_id'     => $tenantId,
            'full_name'     => 'Тестов Тест',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'goods'         => json_encode(['Крем'], JSON_UNESCAPED_UNICODE),
            'quantities'    => json_encode([1]),
            'prices'        => json_encode([10.0]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('orders')->where('id', $id)->update(['status' => $status]);

        return $id;
    }

    public function test_up_replaces_poschitan_without_touching_income_or_stock(): void
    {
        $fixture = $this->seedMigrationFixture();
        $stockBefore = (int) $fixture['product']->fresh()->stock;
        $incomeCountBefore = Income::withoutGlobalScopes()->count();

        $this->runMigrationUp();

        $this->assertSame(0, DB::table('orders')->where('status', 'Посчитан')->count());
        $this->assertSame(0, DB::table('order_status_history')->where('from_status', 'Посчитан')->count());
        $this->assertSame(0, DB::table('order_status_history')->where('to_status', 'Посчитан')->count());

        $this->assertSame(
            'Завершен',
            DB::table('orders')->where('id', $fixture['poschitan_order_id'])->value('status')
        );

        $this->assertNull(
            DB::table('order_status_history')->where('id', $fixture['deleted_z_to_z_id'])->first()
        );
        $this->assertNotNull(
            DB::table('order_status_history')->where('id', $fixture['kept_history_id'])->first()
        );

        $this->assertSame($incomeCountBefore, Income::withoutGlobalScopes()->count());
        $this->assertSame($stockBefore, (int) Product::withoutGlobalScopes()->find($fixture['product']->id)->stock);

        $this->assertTrue(Schema::hasTable('poschitan_status_backup'));
        $this->assertSame(1, DB::table('poschitan_status_backup')->where('source', 'orders')->count());
        $this->assertSame(2, DB::table('poschitan_status_backup')->where('source', 'order_status_history')->count());
    }

    public function test_down_restores_poschitan_order_statuses(): void
    {
        $fixture = $this->seedMigrationFixture();
        $migration = $this->runMigrationUp();

        $migration->down();

        $this->assertSame(
            'Посчитан',
            DB::table('orders')->where('id', $fixture['poschitan_order_id'])->value('status')
        );
        $this->assertFalse(Schema::hasTable('poschitan_status_backup'));
    }
}
