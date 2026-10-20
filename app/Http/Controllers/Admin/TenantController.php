<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request): Response
    {
        Gate::authorize('manage-tenants');

        $tenants = Tenant::query()
            ->when(
                $request->boolean('archived'),
                fn ($q) => $q->whereNotNull('archived_at'),
                fn ($q) => $q->whereNull('archived_at'),
            )
            ->withCount(['users', 'orders'])
            ->with(['users' => fn ($q) => $q->where('role', 'admin')->orderBy('id')])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id'                      => $tenant->id,
                'name'                    => $tenant->name,
                'subscription_status'     => $tenant->effectiveStatus(),
                'stored_status'           => $tenant->subscription_status,
                'trial_ends_at'           => $tenant->trial_ends_at?->toIso8601String(),
                'subscription_ends_at'    => $tenant->subscription_ends_at?->toIso8601String(),
                'subscribed_at'           => $tenant->subscribed_at?->toIso8601String(),
                'read_only'               => $tenant->isReadOnly(),
                'leads_enabled'           => (bool) $tenant->leads_enabled,
                'archived_at'             => $tenant->archived_at?->toIso8601String(),
                'deletion_scheduled_for'  => $tenant->deletion_scheduled_for?->toIso8601String(),
                'admin_email'             => $tenant->users->first()?->email,
                'users_count'             => $tenant->users_count,
                'orders_count'            => $tenant->orders_count,
            ]);

        return Inertia::render('Admin/Tenants/Index', [
            'tenants'  => $tenants,
            'archived' => $request->boolean('archived'),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $data = $request->validate([
            'subscription_status' => ['required', 'in:trial,active,expired,suspended'],
            'trial_ends_at'       => ['nullable', 'date'],
        ]);

        $tenant->update([
            'subscription_status' => $data['subscription_status'],
            'trial_ends_at'       => $data['trial_ends_at'] ?? null,
        ]);

        return back()->with('message', 'Подписка обновлена.');
    }

    public function activate(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $tenant->activate();

        return back()->with('message', 'Тенант активирован.');
    }

    public function extendSubscription(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $tenant->extendSubscription();

        return back()->with('message', 'Подписка продлена на месяц.');
    }

    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $tenant->extendTrial((int) $data['days']);

        return back()->with('message', 'Пробный период продлён.');
    }

    public function setLeadsEnabled(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $actorId = (int) auth()->id();

        if ($enabled) {
            if ($tenant->isArchived()) {
                return back()->with('error', 'Нельзя включить лиды для архивированного тенанта.');
            }

            $status = $tenant->effectiveStatus();
            if ($status !== Tenant::STATUS_TRIAL && $status !== Tenant::STATUS_ACTIVE) {
                return back()->with('error', 'Лиды доступны только при активной подписке или пробном периоде.');
            }

            $tenant->update(['leads_enabled' => true]);
            Log::info('tenant leads enabled', ['tenant_id' => $tenant->id, 'actor_user_id' => $actorId]);

            return back()->with('message', 'Приём лидов включён.');
        }

        $tenant->update(['leads_enabled' => false]);
        Log::info('tenant leads disabled', ['tenant_id' => $tenant->id, 'actor_user_id' => $actorId]);

        return back()->with('message', 'Приём лидов отключён.');
    }

    public function archive(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $tenant->archive();
        Log::info('tenant archived', ['tenant_id' => $tenant->id, 'actor_user_id' => (int) auth()->id()]);

        return back()->with('message', 'Тенант архивирован.');
    }

    public function unarchive(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $tenant->unarchive();
        Log::info('tenant unarchived', ['tenant_id' => $tenant->id, 'actor_user_id' => (int) auth()->id()]);

        return back()->with('message', 'Тенант восстановлен из архива.');
    }

    public function cancelDeletion(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $tenant->cancelDeletion();
        Log::info('tenant deletion cancelled', ['tenant_id' => $tenant->id, 'actor_user_id' => (int) auth()->id()]);

        return back()->with('message', 'Запланированное удаление отменено.');
    }

    public function destroy(Request $request, Tenant $tenant, TenantDeletionService $deletionService): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $data = $request->validate([
            'confirm_name' => ['required', 'string'],
        ]);

        if ($data['confirm_name'] !== $tenant->name) {
            throw ValidationException::withMessages([
                'confirm_name' => ['Название не совпадает.'],
            ]);
        }

        $deletionService->deleteTenant($tenant, (int) auth()->id());

        return redirect()->route('admin.tenants.index')->with('message', 'Тенант удалён.');
    }
}
