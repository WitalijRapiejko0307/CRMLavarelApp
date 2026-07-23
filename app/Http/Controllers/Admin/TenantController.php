<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(): Response
    {
        Gate::authorize('manage-tenants');

        $tenants = Tenant::query()
            ->withCount(['users', 'orders'])
            ->with(['users' => fn ($q) => $q->where('role', 'admin')->limit(1)])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id'                  => $tenant->id,
                'name'                => $tenant->name,
                'subscription_status' => $tenant->effectiveStatus(),
                'stored_status'       => $tenant->subscription_status,
                'trial_ends_at'       => $tenant->trial_ends_at?->toIso8601String(),
                'subscribed_at'       => $tenant->subscribed_at?->toIso8601String(),
                'read_only'           => $tenant->isReadOnly(),
                'admin_email'         => $tenant->users->first()?->email,
                'users_count'         => $tenant->users_count,
                'orders_count'        => $tenant->orders_count,
            ]);

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => $tenants,
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

    public function extendTrial(Request $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('manage-tenants');

        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $tenant->extendTrial((int) $data['days']);

        return back()->with('message', 'Пробный период продлён.');
    }
}
