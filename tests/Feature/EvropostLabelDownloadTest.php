<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvropostLabelDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\VerifyCsrfToken::class);
    }

    private function pdf(string $text = 'L'): string
    {
        $p = new \FPDF('P', 'mm', [100, 100]);
        $p->AddPage();
        $p->SetFont('Arial', '', 12);
        $p->Cell(0, 10, $text);

        return $p->Output('S');
    }

    private function admin(array $settings = ['token_ep' => 'ep-token'], string $status = Tenant::STATUS_ACTIVE): User
    {
        $tenant = Tenant::create([
            'name'                => 'EP Labels ' . uniqid(),
            'type'                => Tenant::TYPE_STORE,
            'created_at'          => now(),
            'subscription_status' => $status,
            'subscribed_at'       => now(),
        ]);

        foreach ($settings as $k => $v) {
            TenantSetting::put($tenant->id, $k, $v);
        }

        return User::create([
            'tenant_id' => $tenant->id,
            'name'      => 'Admin',
            'email'     => 'ep-labels-' . uniqid() . '@example.com',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
        ]);
    }

    private function order(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'tenant_id'         => $user->tenant_id,
            'full_name'         => 'Иванов Иван Иванович',
            'status'            => 'Оформлен',
            'status_changed_at' => now(),
            'delivery_type'     => 'europochta',
            'track_number'      => 'BY' . random_int(10000000, 99999999),
            'phone'             => '291234567',
            'goods'             => ['Крем'],
            'quantities'        => [1],
            'prices'            => [20],
        ], $overrides));
    }

    public function test_labels_are_merged_into_one_pdf_and_format_is_remembered(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY111']);
        $b    = $this->order($user, ['track_number' => 'BY222']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/label/thermal/')) {
                return Http::response($this->pdf('T'), 200, ['Content-Type' => 'application/pdf']);
            }

            return Http::response('', 404);
        });

        $response = $this->actingAs($user)->post('/europochta/labels', [
            'order_ids' => [$a->id, $b->id],
            'format'    => 'thermal',
        ]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/external/label/thermal/BY111')
                && $request->hasHeader('Token', 'ep-token');
        });

        $this->assertSame('thermal', TenantSetting::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)->where('key', 'ep_label_format')->first()->value);
    }

    public function test_default_format_is_the_regular_a5_label(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY333']);

        Http::fake(['*' => Http::response($this->pdf(), 200)]);

        $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$a->id]])->assertOk();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/external/label/address/BY333');
        });
    }

    public function test_only_registered_europochta_orders_with_track_are_requested(): void
    {
        $user  = $this->admin();
        $ok    = $this->order($user, ['track_number' => 'BY444']);
        $sent  = $this->order($user, ['track_number' => 'BY555', 'status' => 'Отправлено']);
        $queue = $this->order($user, ['track_number' => 'BY556', 'status' => 'Отправить']);
        $noTr  = $this->order($user, ['track_number' => null]);
        $bel   = $this->order($user, ['track_number' => 'BY557', 'delivery_type' => 'belpost']);
        $other = $this->admin();
        $alien = $this->order($other, ['track_number' => 'BY999']);

        Http::fake(['*' => Http::response($this->pdf(), 200)]);

        $response = $this->actingAs($user)->post('/europochta/labels', [
            'order_ids' => [$ok->id, $sent->id, $queue->id, $noTr->id, $bel->id, $alien->id],
        ]);

        $response->assertOk();
        $skipped = json_decode(rawurldecode($response->headers->get('X-Label-Skipped')), true);
        $this->assertCount(5, $skipped);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'BY444'));
        foreach (['BY555', 'BY556', 'BY557', 'BY999'] as $track) {
            Http::assertNotSent(fn ($r) => str_contains($r->url(), $track));
        }
    }

    public function test_no_eligible_orders_gives_422(): void
    {
        $user = $this->admin();
        $o    = $this->order($user, ['status' => 'Отправлено']);

        Http::fake();

        $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$o->id]])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_missing_token_and_legacy_mode_give_422(): void
    {
        $noToken = $this->admin([]);
        $o1      = $this->order($noToken);
        $legacy  = $this->admin(['token_ep' => 't', 'ep_api_version' => 'legacy']);
        $o2      = $this->order($legacy);

        Http::fake();

        $this->actingAs($noToken)->post('/europochta/labels', ['order_ids' => [$o1->id]])->assertStatus(422);
        $this->actingAs($legacy)->post('/europochta/labels', ['order_ids' => [$o2->id]])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_merge_failure_delivers_a_zip_of_downloaded_pdfs(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY601']);
        $b    = $this->order($user, ['track_number' => 'BY602']);

        // second "PDF" has a valid signature but cannot be parsed
        Http::fake(function ($request) {
            return str_contains($request->url(), 'BY602')
                ? Http::response('%PDF-1.7 broken', 200)
                : Http::response($this->pdf(), 200);
        });

        $response = $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$a->id, $b->id]]);

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));
        $this->assertSame('failed', $response->headers->get('X-Label-Merge'));
    }

    public function test_partial_download_delivers_what_was_received(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY701']);
        $b    = $this->order($user, ['track_number' => 'BY702']);

        Http::fake(function ($request) {
            return str_contains($request->url(), 'BY702')
                ? Http::response(['message' => 'Почтовое отправление не найдено'], 404)
                : Http::response($this->pdf(), 200);
        });

        $response = $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$a->id, $b->id]]);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $errors = json_decode(rawurldecode($response->headers->get('X-Label-Errors')), true);
        $this->assertSame('BY702', $errors[0]['track']);
    }

    public function test_all_downloads_failing_gives_422(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY801']);

        Http::fake(['*' => Http::response(['message' => 'Ошибка авторизации, перелогиньтесь!'], 401)]);

        $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$a->id]])->assertStatus(422);
    }

    public function test_non_pdf_answer_is_not_delivered(): void
    {
        $user = $this->admin();
        $a    = $this->order($user, ['track_number' => 'BY901']);

        Http::fake(['*' => Http::response('<html>oops</html>', 200)]);

        $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$a->id]])->assertStatus(422);
    }

    public function test_read_only_subscription_is_forbidden(): void
    {
        $user = $this->admin(['token_ep' => 't'], Tenant::STATUS_EXPIRED);
        $o    = $this->order($user);

        Http::fake();

        $this->actingAs($user)->post('/europochta/labels', ['order_ids' => [$o->id]])->assertStatus(403);
    }

    public function test_index_lists_only_fresh_registered_orders(): void
    {
        $user  = $this->admin();
        $fresh = $this->order($user, ['track_number' => 'BY1001']);
        $this->order($user, ['track_number' => 'BY1002', 'status' => 'Отправлено']);
        $this->order($user, ['track_number' => 'BY1003', 'status_changed_at' => now()->subDays(40)]);
        $other = $this->admin();
        $this->order($other, ['track_number' => 'BY1004']);

        $response = $this->actingAs($user)->get('/europochta');

        $response->assertOk();
        $props = $response->viewData('page')['props'];
        $this->assertSame([$fresh->id], collect($props['registeredOrders'])->pluck('id')->all());
        $this->assertSame('a5', $props['labelFormat']);
    }
}
