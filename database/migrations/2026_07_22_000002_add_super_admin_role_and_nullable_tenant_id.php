<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSuperAdminRoleAndNullableTenantId extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE users MODIFY role ENUM('super_admin', 'admin', 'manager', 'operator') NOT NULL DEFAULT 'operator'"
            );

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
            });

            DB::statement('ALTER TABLE users MODIFY tenant_id BIGINT UNSIGNED NULL');

            Schema::table('users', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('CREATE TABLE users__temp AS SELECT * FROM users');
        Schema::drop('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('operator');
            $table->string('theme')->nullable();
            $table->timestamp('tracking_auto_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement(
            'INSERT INTO users (id, tenant_id, name, email, password, role, theme, tracking_auto_seen_at, remember_token, created_at, updated_at)
             SELECT id, tenant_id, name, email, password, role, theme, tracking_auto_seen_at, remember_token, created_at, updated_at FROM users__temp'
        );
        Schema::drop('users__temp');
        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
            });

            DB::table('users')->whereNull('tenant_id')->delete();

            DB::statement('ALTER TABLE users MODIFY tenant_id BIGINT UNSIGNED NOT NULL');

            Schema::table('users', function (Blueprint $table) {
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });

            DB::statement(
                "ALTER TABLE users MODIFY role ENUM('admin', 'manager', 'operator') NOT NULL DEFAULT 'operator'"
            );

            return;
        }

        DB::table('users')->whereNull('tenant_id')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
        });

        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('CREATE TABLE users__temp AS SELECT * FROM users');
        Schema::drop('users');
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('operator');
            $table->string('theme')->nullable();
            $table->timestamp('tracking_auto_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
        DB::statement(
            'INSERT INTO users (id, tenant_id, name, email, password, role, theme, tracking_auto_seen_at, remember_token, created_at, updated_at)
             SELECT id, tenant_id, name, email, password, role, theme, tracking_auto_seen_at, remember_token, created_at, updated_at FROM users__temp'
        );
        Schema::drop('users__temp');
        DB::statement('PRAGMA foreign_keys=ON');
    }
}
