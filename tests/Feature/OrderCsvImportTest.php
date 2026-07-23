<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderCsvImportTest extends TestCase
{
    use RefreshDatabase;

    private function createActiveTenantUser(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Import Co',
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'import@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function sheetsFixture(): string
    {
        return implode("\n", [
            'Шаблон;этикетки;строка;ignored',
            '№ п/п;Дата создания;ФИО;Статус;Товар;Штук;Телефон;Цена за ед.;Вид доставки',
            '100;15.07.2026;Михеева Анна Петровна;Позвонить;Триммер Super, Культиватор Pro;3 3;80291234567;135 123;Белпочта',
            ';;;;;;;',
        ]);
    }

    public function test_imports_google_sheets_csv_with_multiple_line_items(): void
    {
        $user = $this->createActiveTenantUser();
        $file = UploadedFile::fake()->createWithContent('orders.csv', $this->sheetsFixture());

        $response = $this->actingAs($user)->post('/orders/import-csv', ['file' => $file]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'created' => 1,
            'skipped' => 0,
            'errors'  => 0,
        ]);

        $order = Order::withoutGlobalScopes()->first();
        $this->assertNotNull($order);
        $this->assertSame('100', $order->external_id);
        $this->assertSame('Михеева Анна Петровна', $order->full_name);
        $this->assertSame(['Триммер Super', 'Культиватор Pro'], $order->goods);
        $this->assertSame([3, 3], $order->quantities);
        $this->assertSame([135, 123], $order->prices);
        $this->assertSame('belpost', $order->delivery_type);
    }

    public function test_duplicate_external_id_is_skipped(): void
    {
        $user = $this->createActiveTenantUser();
        $file = UploadedFile::fake()->createWithContent('orders.csv', $this->sheetsFixture());

        $this->actingAs($user)->post('/orders/import-csv', ['file' => $file]);

        $file2 = UploadedFile::fake()->createWithContent('orders2.csv', $this->sheetsFixture());
        $response = $this->actingAs($user)->post('/orders/import-csv', ['file' => $file2]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'created' => 0,
            'skipped' => 1,
        ]);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    public function test_line_item_mismatch_adds_warning_without_stopping_import(): void
    {
        $user = $this->createActiveTenantUser();

        $csv = implode("\n", [
            '№ п/п;ФИО;Товар;Штук;Цена за ед.',
            '1;Иванов Иван Иванович;Товар А, Товар Б;1;100',
            '2;Петров Петр Петрович;Один товар;1;50',
        ]);
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $response = $this->actingAs($user)->post('/orders/import-csv', ['file' => $file]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'created' => 1,
            'errors'  => 1,
        ]);

        $payload = $response->json();
        $this->assertCount(1, $payload['warnings']);
        $this->assertSame(2, $payload['warnings'][0]['row']);

        $order = Order::withoutGlobalScopes()->first();
        $this->assertSame('2', $order->external_id);
        $this->assertSame(['Один товар'], $order->goods);
    }

    public function test_delivery_cost_column_does_not_break_import(): void
    {
        $user = $this->createActiveTenantUser();

        $csv = implode("\n", [
            '№ п/п;ФИО;Товар;Штук;Цена за ед.;Доставка;Вид доставки',
            '7;Сидоров Сидор Сидорович;Товар;1;100;Бесплатно;Белпочта',
        ]);
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $response = $this->actingAs($user)->post('/orders/import-csv', ['file' => $file]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'created' => 1, 'errors' => 0]);

        $order = Order::withoutGlobalScopes()->first();
        $this->assertSame('belpost', $order->delivery_type);
    }

    public function test_import_preserves_call_center_status(): void
    {
        $user = $this->createActiveTenantUser();

        $csv = implode("\n", [
            '№ п/п;ФИО;Статус;Товар;Штук;Цена за ед.',
            '55;Иванов Иван Иванович;Недозвон1;Товар;1;100',
        ]);
        $file = UploadedFile::fake()->createWithContent('orders.csv', $csv);

        $response = $this->actingAs($user)->post('/orders/import-csv', ['file' => $file]);

        $response->assertOk();
        $response->assertJson([
            'success'  => true,
            'created'  => 1,
            'errors'   => 0,
            'warnings' => [],
        ]);

        $order = Order::withoutGlobalScopes()->first();
        $this->assertSame('Недозвон1', $order->status);
    }
}
