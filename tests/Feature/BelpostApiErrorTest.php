<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\BelpostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BelpostApiErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_list_401_mentions_settings_token(): void
    {
        $tenant = Tenant::create([
            'name'                => 'BP Co ' . uniqid(),
            'created_at'          => now(),
            'subscription_status' => Tenant::STATUS_ACTIVE,
            'subscribed_at'       => now(),
        ]);

        app()->instance('current_tenant_id', $tenant->id);

        Http::fake([
            'api.belpost.by/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Проверьте токен Белпочты в Настройках');

        (new BelpostService($tenant->id))->createList('parcel', 'Покупатель');
    }
}
