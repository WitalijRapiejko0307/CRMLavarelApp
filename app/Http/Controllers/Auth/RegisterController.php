<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function showRegistrationForm(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'name'         => ['required', 'string', 'max:255'],
            'email'        => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name'                => $data['company_name'],
                'created_at'          => now(),
                'subscription_status' => Tenant::STATUS_TRIAL,
                'trial_ends_at'       => now()->addDays(config('subscription.trial_days', 14)),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'name'      => $data['name'],
                'email'     => $data['email'],
                'password'  => Hash::make($data['password']),
                'role'      => 'admin',
            ]);

            app(TenantProvisioner::class)->provision($tenant, $data['company_name']);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/settings');
    }
}
