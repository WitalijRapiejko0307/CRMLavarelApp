<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VerifyAnalyticsDemo extends Command
{
    protected $signature = 'analytics:verify-demo {--http : Also hit /analytics via HTTP kernel}';

    protected $description = 'Verify analytics demo data (managers + stores tabs, operator scope)';

    public function handle(AnalyticsService $analytics): int
    {
        $admin = User::where('email', 'cc-admin@demo.by')->first();
        $operator = User::where('email', 'cc-operator@demo.by')->first();

        if (!$admin || !$operator) {
            $this->error('Run: php artisan db:seed --class=AnalyticsDemoSeeder');

            return self::FAILURE;
        }

        $dateFrom = now()->subDays(30)->toDateString();
        $dateTo   = now()->toDateString();

        $this->info('=== Admin / managers tab ===');
        $adminManagers = $analytics->forCallCenter(
            $admin->tenant_id,
            $admin,
            'managers',
            $dateFrom,
            $dateTo,
        );
        $this->table(
            ['Менеджер', 'Касания', 'Подтв.', 'Отказы', 'Спам', 'Недозв.', 'Заказы'],
            collect($adminManagers['rows'])->map(fn ($r) => [
                $r['name'],
                $r['touches'],
                $r['confirmed'],
                $r['refusals'],
                $r['spam'],
                $r['no_answer'],
                $r['unique_orders'],
            ])->all()
        );
        $this->line('Summary touches: ' . ($adminManagers['summary']['touches'] ?? 0));

        $this->info('=== Operator / managers tab (own only) ===');
        $opManagers = $analytics->forCallCenter(
            $operator->tenant_id,
            $operator,
            'managers',
            $dateFrom,
            $dateTo,
        );
        $this->table(
            ['Менеджер', 'Касания', 'Подтв.', 'Отказы', 'Спам', 'Недозв.', 'Заказы'],
            collect($opManagers['rows'])->map(fn ($r) => [
                $r['name'],
                $r['touches'],
                $r['confirmed'],
                $r['refusals'],
                $r['spam'],
                $r['no_answer'],
                $r['unique_orders'],
            ])->all()
        );
        $this->line('Operator rows: ' . count($opManagers['rows']) . ' (expected 1)');

        $this->info('=== Admin / stores tab ===');
        $adminStores = $analytics->forCallCenter(
            $admin->tenant_id,
            $admin,
            'stores',
            $dateFrom,
            $dateTo,
        );
        $this->table(
            ['Магазин', 'Лиды', 'Подтв.', 'Отказы', 'Спам', 'Недозв.', 'Конв.%'],
            collect($adminStores['rows'])->map(fn ($r) => [
                $r['name'],
                $r['leads'],
                $r['confirmed'],
                $r['refusals'],
                $r['spam'],
                $r['no_answer'],
                $r['conversion'],
            ])->all()
        );

        $httpOk = true;
        if ($this->option('http')) {
            $this->info('=== HTTP /analytics ===');
            $httpOk = $this->verifyHttp($admin, $operator);
        }

        $passed = count($adminManagers['rows']) === 3
            && count($opManagers['rows']) === 1
            && count($adminStores['rows']) === 2
            && ($adminManagers['summary']['touches'] ?? 0) >= 8
            && $httpOk;

        if ($passed) {
            $this->info('All checks passed.');

            return self::SUCCESS;
        }

        $this->error('Checks failed — review output above.');

        return self::FAILURE;
    }

    private function verifyHttp(User $admin, User $operator): bool
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $ok     = true;

        foreach ([
            [$admin, '/analytics?tab=managers', 200],
            [$operator, '/analytics?tab=managers', 200],
            [$operator, '/analytics?tab=stores', 200],
        ] as [$user, $url, $expected]) {
            Auth::login($user);
            $request = Request::create($url, 'GET');
            $request->setUserResolver(fn () => $user);
            $response = $kernel->handle($request);
            $status   = $response->getStatusCode();
            $this->line("{$user->email} GET {$url} => {$status}");
            if ($status !== $expected) {
                $ok = false;
            }
            $kernel->terminate($request, $response);
        }

        $storeUser = User::where('email', 'admin@crm.by')->first();
        if ($storeUser) {
            Auth::login($storeUser);
            $request = Request::create('/analytics', 'GET');
            $request->setUserResolver(fn () => $storeUser);
            $response = $kernel->handle($request);
            $status   = $response->getStatusCode();
            $this->line("{$storeUser->email} GET /analytics => {$status} (expected 403)");
            if ($status !== 403) {
                $ok = false;
            }
            $kernel->terminate($request, $response);
        }

        return $ok;
    }
}
