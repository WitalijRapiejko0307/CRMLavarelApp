<?php

namespace App\Http\Controllers;

use App\Services\ShopFunnelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        protected ShopFunnelService $funnel,
    ) {
        $this->middleware(['auth', 'tenant', 'tenant.type:store', 'can:view-reports']);
    }

    /**
     * GET /reports
     */
    public function index(Request $request): Response
    {
        $dateFrom = $request->input('date_from') ?: null;
        $dateTo = $request->input('date_to') ?: null;
        $utmCampaign = $request->input('utm_campaign') ?: null;

        $data = $this->funnel->forStore(Auth::user()->tenant_id, $dateFrom, $dateTo, $utmCampaign);

        return Inertia::render('Reports/Index', $data);
    }
}
