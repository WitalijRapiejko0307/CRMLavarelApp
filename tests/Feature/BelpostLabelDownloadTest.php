<?php

namespace Tests\Feature;

use App\Models\MailBatch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\BelpostLabelService;
use App\Support\LabelSheetLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BelpostLabelDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function createStoreAdmin(): User
    {
        $tenant = Tenant::create([
            'name'                => 'Belpost P4 Co',
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'belpost-p4-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function seedSender(User $user): void
    {
        TenantSetting::put($user->tenant_id, 'belpost_sender_name', 'ИП Тестов Тест Тестович');
        TenantSetting::put($user->tenant_id, 'belpost_sender_street', 'пр. Купалы, д. 1');
        TenantSetting::put($user->tenant_id, 'belpost_sender_postcode', '230010');
        TenantSetting::put($user->tenant_id, 'belpost_sender_city', 'г. Гродно');
        TenantSetting::put($user->tenant_id, 'belpost_contract_no', '25664');
        TenantSetting::put($user->tenant_id, 'belpost_contract_date', '15.07.2025');
        TenantSetting::put($user->tenant_id, 'shelf_life', '10');
    }

    private function createDraftBatch(User $user, array $overrides = []): MailBatch
    {
        return MailBatch::create(array_merge([
            'tenant_id'         => $user->tenant_id,
            'batch_id'          => 'bp-' . uniqid(),
            'type'              => 'ecommerce_elite',
            'who_pays'          => 'Покупатель',
            'label_size'        => '210x150',
            'belpost_committed' => false,
            'status'            => MailBatch::STATUS_DRAFT,
        ], $overrides));
    }

    private function createBatchOrder(User $user, MailBatch $batch, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'     => $user->tenant_id,
            'full_name'     => 'Иванов Иван Иванович',
            'status'        => 'Оформлен',
            'delivery_type' => 'belpost',
            'mail_batch_id' => $batch->id,
            'track_number'  => null,
            'phone'         => '291234567',
            'city'          => '230010 Гродно',
            'street'        => 'Ленина',
            'building'      => '1',
            'goods'         => ['Крем'],
            'quantities'    => [1],
            'prices'        => [20.5],
        ], $overrides));
    }

    public function test_two_tracked_orders_download_pdf_on_one_page(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, [
            'track_number' => 'PC111111111BY',
            'full_name'    => 'Первый Получатель Тестов',
        ]);
        $this->createBatchOrder($user, $batch, [
            'track_number' => 'PC222222222BY',
            'full_name'    => 'Второй Получатель Тестов',
        ]);

        Http::fake();

        $response = $this->actingAs($user)
            ->post("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '150x100',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $binary = $response->getContent();
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertTrue(
            $this->pdfContainsTrack($binary, 'PC111111111BY'),
            'PDF should contain first track'
        );
        $this->assertTrue(
            $this->pdfContainsTrack($binary, 'PC222222222BY'),
            'PDF should contain second track'
        );

        $layout = LabelSheetLayout::forLabelSize('150x100');
        $this->assertSame(1, $layout->pageCount(2));

        $batch->refresh();
        $this->assertSame('150x100', $batch->label_size);
        $this->assertNull($batch->pdf_path);
        $this->assertSame(MailBatch::STATUS_DRAFT, $batch->status);

        $service = new BelpostLabelService();
        app()->instance('current_tenant_id', $user->tenant_id);
        $labels  = $service->labelsForOrders($batch, $service->trackedOrders($batch));
        $tracks  = array_column($labels, 'track_number');
        $this->assertEqualsCanonicalizing(['PC111111111BY', 'PC222222222BY'], $tracks);
        $this->assertSame('Пакет Элит', $labels[0]['package_title']);
        $this->assertNotSame('', $labels[0]['barcode_markup']);
        $this->assertTrue($labels[0]['show_ecommerce_logo']);
        $this->assertNotSame('', $labels[0]['ecommerce_logo_path']);
        $labelHtml = view('pdf.belpost-label', ['label' => $labels[0]])->render();
        $this->assertStringNotContainsString('brand-word', $labelHtml);
        $this->assertStringNotContainsString('>-commerce<', $labelHtml);
        $this->assertStringContainsString('e-commerce', $labelHtml);
        $this->assertSame('Вес ___________', $labels[0]['weight_text']);
        $this->assertStringNotContainsString('кг', $labels[0]['weight_text']);
        $this->assertTrue(
            str_contains($labels[0]['barcode_markup'], 'barcode')
            || str_contains($labels[0]['barcode_markup'], '<svg')
            || str_contains($labels[0]['barcode_markup'], 'data:image/png'),
            'Each label must include barcode markup'
        );

        Http::assertNothingSent();
    }

    public function test_two_tracked_210x150_fit_one_a4_page(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC222222222BY']);

        Http::fake();

        $response = $this->actingAs($user)
            ->post("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '210x150',
            ]);

        $response->assertOk();
        $binary = $response->getContent();
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertTrue($this->pdfContainsTrack($binary, 'PC111111111BY'));
        $this->assertTrue($this->pdfContainsTrack($binary, 'PC222222222BY'));

        $layout = LabelSheetLayout::forLabelSize('210x150');
        $this->assertSame(2, $layout->perPage());
        $this->assertSame(1, $layout->pageCount(2));
        $this->assertFalse($layout->rotated());
        $this->assertTrue($layout->usesA4());

        Http::assertNothingSent();
    }

    public function test_120x80_uses_thermal_page_size(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);

        $response = $this->actingAs($user)
            ->post("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '120x80',
            ]);

        $response->assertOk();
        $binary = $response->getContent();
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertTrue($this->pdfContainsTrack($binary, 'PC111111111BY'));

        $layout = LabelSheetLayout::forLabelSize('120x80');
        $this->assertSame(1, $layout->perPage());
        $this->assertFalse($layout->usesA4());

        $box = $this->pdfMediaBox($binary);
        $this->assertNotNull($box, 'PDF should declare a MediaBox');
        $expectedW = 120.0 * 72 / 25.4;
        $expectedH = 80.0 * 72 / 25.4;
        $this->assertEqualsWithDelta($expectedW, $box[0], 2.0, '120x80 width pt');
        $this->assertEqualsWithDelta($expectedH, $box[1], 2.0, '120x80 height pt');
    }

    public function test_three_tracked_150x100_span_two_pages(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user, ['label_size' => '150x100']);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC222222222BY']);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC333333333BY']);

        $this->assertSame(2, LabelSheetLayout::forLabelSize('150x100')->pageCount(3));

        $response = $this->actingAs($user)
            ->post("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '150x100',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertGreaterThanOrEqual(1, LabelSheetLayout::forLabelSize('150x100')->pageCount(3));
    }

    public function test_no_tracks_returns_422_without_pdf(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => null]);

        $response = $this->actingAs($user)
            ->postJson("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '150x100',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        $this->assertStringContainsString('бланки', (string) $response->json('message'));
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_empty_sender_name_returns_422_mentioning_settings(): void
    {
        $user  = $this->createStoreAdmin();
        TenantSetting::put($user->tenant_id, 'belpost_sender_name', '');
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);

        $response = $this->actingAs($user)
            ->postJson("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '150x100',
            ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('отправителя', $message);
        $this->assertStringContainsString('Настройках', $message);
        $this->assertStringNotContainsString('%PDF', (string) $response->getContent());
    }

    public function test_download_does_not_call_belpost_http(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);

        Http::fake();

        $this->actingAs($user)
            ->post("/belpost/batches/{$batch->id}/download-blanks", [
                'label_size' => '150x100',
            ])
            ->assertOk();

        Http::assertNothingSent();
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'api.belpost.by')
                || str_contains($request->url(), 'generate-blank');
        });
    }

    public function test_get_labels_pdf_streams_same_pdf(): void
    {
        $user  = $this->createStoreAdmin();
        $this->seedSender($user);
        $batch = $this->createDraftBatch($user);
        $this->createBatchOrder($user, $batch, ['track_number' => 'PC111111111BY']);

        $response = $this->actingAs($user)
            ->get("/belpost/batches/{$batch->id}/labels.pdf?label_size=150x100");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertTrue(
            $this->pdfContainsTrack($response->getContent(), 'PC111111111BY'),
            'GET labels.pdf should contain the track'
        );
    }

    public function test_blank_type_label_maps_ecommerce_and_falls_back(): void
    {
        $this->assertSame('Пакет Элит', MailBatch::blankTypeLabel('ecommerce_elite'));
        $this->assertSame('Пакет Стандарт', MailBatch::blankTypeLabel('ecommerce_standard'));
        $this->assertSame('Пакет Эконом', MailBatch::blankTypeLabel('ecommerce_economical'));
        $this->assertSame('Пакет Экспресс', MailBatch::blankTypeLabel('ecommerce_express'));
        $this->assertSame('Пакет Лайт', MailBatch::blankTypeLabel('ecommerce_light'));
        $this->assertSame('Пакет Оптима', MailBatch::blankTypeLabel('ecommerce_optima'));
        $this->assertSame(MailBatch::DELIVERY_TYPES['package'], MailBatch::blankTypeLabel('package'));
        $this->assertSame('', MailBatch::blankTypeLabel(null));
    }

    private function pdfContainsTrack(string $binary, string $track): bool
    {
        if (str_contains($binary, $track)) {
            return true;
        }

        $utf16 = "\xfe\xff" . implode("\x00", str_split($track));
        if (str_contains($binary, $utf16) || str_contains($binary, implode("\x00", str_split($track)))) {
            return true;
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private function pdfMediaBox(string $binary): ?array
    {
        if (!preg_match('/\/MediaBox\s*\[\s*([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s+([0-9.]+)\s*\]/', $binary, $m)) {
            return null;
        }

        return [
            (float) $m[3] - (float) $m[1],
            (float) $m[4] - (float) $m[2],
        ];
    }
}
