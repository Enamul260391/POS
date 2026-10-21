<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The test suite must never touch a real database.
 *
 * `RefreshDatabase` prepares the database by running `migrate:fresh`, which
 * drops every table. Against the local MySQL database or production that
 * would destroy the data — and the connection the suite resolves is decided by
 * the *environment*, because Laravel's `env()` reads `$_SERVER` before
 * `getenv()` and a shell that exported `DB_CONNECTION` would silently win.
 *
 * `phpunit.xml` forces the isolated database and `Tests\TestCase::setUp()`
 * refuses to run against anything else. These assertions are the readable
 * statement of that contract: if they ever fail, the suite is one environment
 * variable away from a real database.
 */
class TestDatabaseIsolationTest extends TestCase
{
    /** @test */
    public function the_test_environment_is_the_testing_environment(): void
    {
        $this->assertSame('testing', config('app.env'));
    }

    /** @test */
    public function the_test_database_is_the_in_memory_sqlite_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));

        $connection = config('database.connections.'.config('database.default'));

        $this->assertSame('sqlite', $connection['driver']);
        $this->assertSame(':memory:', $connection['database']);
    }

    /** @test */
    public function the_environment_forces_the_isolated_database(): void
    {
        // The values the application resolves come from the environment, so
        // these are the ones that must hold regardless of what the shell said.
        $this->assertSame('sqlite', getenv('DB_CONNECTION'));
        $this->assertSame(':memory:', getenv('DB_DATABASE'));
        $this->assertSame('testing', getenv('APP_ENV'));
    }
}
