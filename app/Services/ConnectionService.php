<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantConnection;
use App\Models\TenantSetting;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnectionService
{
    public function generateConnectionCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while ($this->findCallCenterByCode($code) !== null);

        return $code;
    }

    public function findCallCenterByCode(string $code): ?Tenant
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return null;
        }

        $settings = TenantSetting::withoutGlobalScopes()
            ->where('key', 'connection_code')
            ->get();

        foreach ($settings as $setting) {
            if (strtoupper((string) $setting->value) === $code) {
                $tenant = Tenant::find($setting->tenant_id);

                return ($tenant && $tenant->isCallCenter()) ? $tenant : null;
            }
        }

        return null;
    }

    public function requestConnection(Tenant $store, string $code): TenantConnection
    {
        if (!$store->isStore()) {
            throw ValidationException::withMessages([
                'code' => 'Подключение доступно только для интернет-магазинов.',
            ]);
        }

        $callCenter = $this->findCallCenterByCode($code);

        if (!$callCenter) {
            throw ValidationException::withMessages([
                'code' => 'Код колл-центра не найден.',
            ]);
        }

        if ($callCenter->id === $store->id) {
            throw ValidationException::withMessages([
                'code' => 'Нельзя подключиться к самому себе.',
            ]);
        }

        if ($this->storeHasActiveConnection($store->id)) {
            throw ValidationException::withMessages([
                'code' => 'У магазина уже есть активное подключение к колл-центру.',
            ]);
        }

        $existing = TenantConnection::where('store_tenant_id', $store->id)
            ->where('call_center_tenant_id', $callCenter->id)
            ->first();

        if ($existing) {
            if (in_array($existing->status, [TenantConnection::STATUS_PENDING, TenantConnection::STATUS_ACTIVE], true)) {
                throw ValidationException::withMessages([
                    'code' => 'Заявка на это подключение уже существует.',
                ]);
            }

            $existing->update([
                'status'          => TenantConnection::STATUS_PENDING,
                'requested_at'    => now(),
                'approved_at'     => null,
                'rejected_at'     => null,
                'disconnected_at' => null,
            ]);

            return $existing->fresh(['store', 'callCenter']);
        }

        return TenantConnection::create([
            'store_tenant_id'       => $store->id,
            'call_center_tenant_id' => $callCenter->id,
            'status'                => TenantConnection::STATUS_PENDING,
            'requested_at'          => now(),
        ])->load(['store', 'callCenter']);
    }

    public function approve(TenantConnection $connection, Tenant $callCenter): TenantConnection
    {
        $this->assertCallCenterOwns($connection, $callCenter);

        if ($connection->status !== TenantConnection::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'connection' => 'Заявка уже обработана.',
            ]);
        }

        if ($this->storeHasActiveConnection($connection->store_tenant_id, $connection->id)) {
            throw ValidationException::withMessages([
                'connection' => 'У магазина уже есть активное подключение.',
            ]);
        }

        $connection->update([
            'status'      => TenantConnection::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        return $connection->fresh(['store', 'callCenter']);
    }

    public function reject(TenantConnection $connection, Tenant $callCenter): TenantConnection
    {
        $this->assertCallCenterOwns($connection, $callCenter);

        if ($connection->status !== TenantConnection::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'connection' => 'Заявка уже обработана.',
            ]);
        }

        $connection->update([
            'status'      => TenantConnection::STATUS_REJECTED,
            'rejected_at' => now(),
        ]);

        return $connection->fresh(['store', 'callCenter']);
    }

    public function disconnect(TenantConnection $connection, Tenant $tenant): TenantConnection
    {
        if ($connection->status !== TenantConnection::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'connection' => 'Можно отключить только активное подключение.',
            ]);
        }

        if ($tenant->id !== $connection->store_tenant_id && $tenant->id !== $connection->call_center_tenant_id) {
            abort(403);
        }

        $connection->update([
            'status'          => TenantConnection::STATUS_DISCONNECTED,
            'disconnected_at' => now(),
        ]);

        return $connection->fresh(['store', 'callCenter']);
    }

    public function regenerateCode(Tenant $callCenter): string
    {
        if (!$callCenter->isCallCenter()) {
            abort(403);
        }

        $code = $this->generateConnectionCode();
        TenantSetting::put($callCenter->id, 'connection_code', $code);

        TenantConnection::where('call_center_tenant_id', $callCenter->id)
            ->where('status', TenantConnection::STATUS_PENDING)
            ->update([
                'status'      => TenantConnection::STATUS_REJECTED,
                'rejected_at' => now(),
            ]);

        return $code;
    }

    public function storeHasActiveConnection(int $storeTenantId, ?int $exceptConnectionId = null): bool
    {
        $query = TenantConnection::where('store_tenant_id', $storeTenantId)
            ->where('status', TenantConnection::STATUS_ACTIVE);

        if ($exceptConnectionId) {
            $query->where('id', '!=', $exceptConnectionId);
        }

        return $query->exists();
    }

    public function activeConnectionForStore(int $storeTenantId): ?TenantConnection
    {
        return TenantConnection::where('store_tenant_id', $storeTenantId)
            ->where('status', TenantConnection::STATUS_ACTIVE)
            ->first();
    }

    public function connectionsForSettings(Tenant $tenant): array
    {
        if ($tenant->isStore()) {
            $connections = TenantConnection::where('store_tenant_id', $tenant->id)
                ->with('callCenter:id,name')
                ->orderByDesc('requested_at')
                ->get();

            return [
                'connections'        => $connections,
                'connection_code'    => null,
                'active_connection'  => $connections->firstWhere('status', TenantConnection::STATUS_ACTIVE),
                'pending_connection' => $connections->firstWhere('status', TenantConnection::STATUS_PENDING),
            ];
        }

        if ($tenant->isCallCenter()) {
            $connections = TenantConnection::where('call_center_tenant_id', $tenant->id)
                ->with('store:id,name')
                ->orderByDesc('requested_at')
                ->get();

            return [
                'connections'        => $connections,
                'connection_code'    => TenantSetting::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('key', 'connection_code')
                    ->value('value'),
                'pending_connections' => $connections->where('status', TenantConnection::STATUS_PENDING)->values(),
                'active_connections'  => $connections->where('status', TenantConnection::STATUS_ACTIVE)->values(),
            ];
        }

        return ['connections' => collect()];
    }

    protected function assertCallCenterOwns(TenantConnection $connection, Tenant $callCenter): void
    {
        if ($connection->call_center_tenant_id !== $callCenter->id) {
            abort(403);
        }
    }
}
