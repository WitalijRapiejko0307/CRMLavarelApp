<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        $connection = getenv('DB_CONNECTION') ?: (string) env('DB_CONNECTION');
        $database   = getenv('DB_DATABASE') ?: (string) env('DB_DATABASE');
        if ($connection === 'mysql' && $database === 'crm') {
            throw new \RuntimeException(
                'Refusing to run tests against the live MySQL database [crm]. Use sqlite :memory: or a dedicated test database.'
            );
        }

        if (env('DB_CONNECTION') === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is not available.');
        }

        if (env('DB_CONNECTION') === 'mysql' && !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not available.');
        }

        parent::setUp();

        $default = (string) config('database.default');
        $name    = (string) config("database.connections.{$default}.database");
        if ($default === 'mysql' && $name === 'crm') {
            throw new \RuntimeException(
                'Refusing to run tests against the live MySQL database [crm]. Use sqlite :memory: or a dedicated test database.'
            );
        }

        if (env('DB_CONNECTION') === 'mysql') {
            try {
                \Illuminate\Support\Facades\DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $this->markTestSkipped('MySQL connection is not available.');
            }
        }
    }
}
