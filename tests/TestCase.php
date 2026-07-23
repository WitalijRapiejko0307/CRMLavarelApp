<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        if (env('DB_CONNECTION') === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is not available.');
        }

        if (env('DB_CONNECTION') === 'mysql' && !extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql extension is not available.');
        }

        parent::setUp();

        if (env('DB_CONNECTION') === 'mysql') {
            try {
                \Illuminate\Support\Facades\DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $this->markTestSkipped('MySQL connection is not available.');
            }
        }
    }
}
