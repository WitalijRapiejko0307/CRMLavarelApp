<?php

namespace App\Http\Controllers;

use App\Models\TenantConnection;
use App\Services\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ConnectionController extends Controller
{
    public function __construct(
        protected ConnectionService $connectionService
    ) {
        $this->middleware(['auth', 'tenant']);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('manage-connections');

        $data = $this->connectionService->connectionsForSettings(Auth::user()->tenant);

        return response()->json($data);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-connections');

        $data = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:20'],
        ]);

        $tenant = Auth::user()->tenant;
        abort_unless($tenant->isStore(), 403);

        $this->connectionService->requestConnection($tenant, $data['code']);

        return back()->with('message', 'Заявка на подключение отправлена.');
    }

    public function approve(TenantConnection $connection): RedirectResponse
    {
        Gate::authorize('approve-connections');

        $this->connectionService->approve($connection, Auth::user()->tenant);

        return back()->with('message', 'Подключение одобрено.');
    }

    public function reject(TenantConnection $connection): RedirectResponse
    {
        Gate::authorize('approve-connections');

        $this->connectionService->reject($connection, Auth::user()->tenant);

        return back()->with('message', 'Заявка отклонена.');
    }

    public function disconnect(TenantConnection $connection): RedirectResponse
    {
        Gate::authorize('manage-connections');

        $tenant = Auth::user()->tenant;

        if ($tenant->id !== $connection->store_tenant_id && $tenant->id !== $connection->call_center_tenant_id) {
            abort(403);
        }

        $this->connectionService->disconnect($connection, $tenant);

        return back()->with('message', 'Подключение отключено.');
    }

    public function regenerateConnectionCode(): JsonResponse
    {
        Gate::authorize('approve-connections');

        $tenant = Auth::user()->tenant;
        abort_unless($tenant->isCallCenter(), 403);

        $code = $this->connectionService->regenerateCode($tenant);

        return response()->json(['success' => true, 'code' => $code]);
    }
}
