<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSubscriptionToTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->enum('subscription_status', ['trial', 'active', 'expired', 'suspended'])
                ->default('trial')
                ->after('name');
            $table->timestamp('trial_ends_at')->nullable()->after('subscription_status');
            $table->timestamp('subscribed_at')->nullable()->after('trial_ends_at');
        });

        DB::table('tenants')->update([
            'subscription_status' => 'active',
            'subscribed_at'       => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'trial_ends_at', 'subscribed_at']);
        });
    }
}
