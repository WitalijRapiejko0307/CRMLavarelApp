<?php

namespace App\Http\Controllers;

use App\Models\TenantConnection;
use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analytics
    ) {
        $this->middleware(['auth', 'tenant', 'tenant.type:call_center', 'can:view-analytics']);
    }

    /**
     * GET /analytics
     */
    public function index(Request $request): Response
    {
        $user   = Auth::user();
        $tenant = $user->tenant;

        $tab      = $request->input('tab', 'managers');
        $dateFrom = $request->input('date_from');
        $dateTo   = $request->input('date_to');
        $storeId  = $request->input('store_id') ? (int) $request->input('store_id') : null;
        $userId   = $request->input('user_id') ? (int) $request->input('user_id') : null;

        if (!in_array($tab, ['managers', 'stores'], true)) {
            $tab = 'managers';
        }

        $data = $this->analytics->forCallCenter(
            $tenant->id,
            $user,
            $tab,
            $dateFrom,
            $dateTo,
            $storeId,
            $userId,
        );

        $connectedStores = TenantConnection::where('call_center_tenant_id', $tenant->id)
            ->where('status', TenantConnection::STATUS_ACTIVE)
            ->with('store:id,name')
            ->get()
            ->map(fn ($c) => ['id' => $c->store->id, 'name' => $c->store->name]);

        $teamMembers = Gate::check('view-team-analytics')
            ? User::where('tenant_id', $tenant->id)
                ->whereIn('role', ['admin', 'manager', 'operator'])
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        return Inertia::render('Analytics/Index', [
            'summary'         => $data['summary'],
            'rows'            => $data['rows'],
            'filters'         => $data['filters'],
            'canFilterTeam'   => $data['canFilterTeam'],
            'connectedStores' => $connectedStores,
            'teamMembers'     => $teamMembers,
        ]);
    }
}
