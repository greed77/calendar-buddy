<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes whatever database it is pointed at. If a cached
        // config (php artisan optimize) leaks the real MySQL settings in, stop here.
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Tests must use the in-memory SQLite database. Run `php artisan optimize:clear` and try again.');
        }
    }
}
