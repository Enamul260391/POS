<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run the test suite against anything but the throwaway database.
     *
     * `RefreshDatabase` prepares the database by running `migrate:fresh`, which
     * DROPS every table and re-runs every migration. That is harmless against
     * an in-memory SQLite database and catastrophic against the local MySQL
     * database or production — and the connection the suite actually ends up
     * using is decided by the *environment*, not by `phpunit.xml`: PHPUnit
     * applies an `<env>` entry only when the variable is not already set, so a
     * shell that exported `DB_CONNECTION=mysql` would silently send the whole
     * suite to the live database.
     *
     * `phpunit.xml` now forces the isolated connection (`force="true"`), and
     * this guard is the backstop for anything that bypasses it.
     *
     * ## Why the check lives here
     *
     * `RefreshDatabase` defines `refreshDatabase()`, `refreshTestDatabase()` and
     * `migrateDatabases()`, and because a test class *uses* that trait, the
     * trait's methods are flattened into the test class itself and shadow any
     * override declared in this base class — an override of
     * `migrateDatabases()` here would never run. `setUp()` is the one hook the
     * trait does not define, so the guard here always runs.
     *
     * The environment is checked *before* the application boots (that is what
     * decides the connection), and the resolved configuration is checked
     * *after* it boots but *before* `setUpTraits()` reaches `migrate:fresh`.
     */
    protected function setUp(): void
    {
        $this->assertIsolatedTestEnvironment();

        // Boot the application here so the connection it actually resolved can
        // be inspected before any trait touches the database. `setUpTheTestEnvironment()`
        // only creates the application when none exists, so this one is kept.
        $this->refreshApplication();

        $this->assertIsolatedTestConnection();

        parent::setUp();
    }

    /**
     * The environment must name the isolated test database.
     *
     * Read the same way Laravel's `env()` reads it — `getenv()` and `$_SERVER`
     * — because that is what decides the connection the application resolves.
     *
     * @throws RuntimeException when the environment points somewhere else.
     */
    private function assertIsolatedTestEnvironment(): void
    {
        // A cached configuration would ignore the environment entirely, so the
        // forced values in phpunit.xml would have no effect. Refuse rather than
        // run against whatever the cache holds.
        if (file_exists($this->appPath('bootstrap/cache/config.php'))) {
            throw new RuntimeException(
                'Refusing to run the test suite with a cached configuration: '
                .'bootstrap/cache/config.php would override the forced test environment. '
                .'Remove it with "php artisan config:clear" before running the tests.'
            );
        }

        $appEnv = $this->environmentValue('APP_ENV');
        $connection = $this->environmentValue('DB_CONNECTION');
        $database = $this->environmentValue('DB_DATABASE');

        if ($appEnv === 'testing' && $connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run the test suite against a real database: the environment names a %s database ("%s") in the "%s" environment. '
            .'The suite may only use the in-memory SQLite database, because preparing it drops every table. '
            .'Unset DB_CONNECTION and DB_DATABASE in your shell (phpunit.xml forces them), and confirm APP_ENV is "testing".',
            $connection === '' ? 'unknown' : $connection,
            $database === '' ? 'unknown' : $database,
            $appEnv === '' ? 'unknown' : $appEnv,
        ));
    }

    /**
     * The connection the application actually resolved must be the isolated one.
     *
     * @throws RuntimeException when the resolved connection is not isolated.
     */
    private function assertIsolatedTestConnection(): void
    {
        $connection = (string) config('database.default');
        $config = config('database.connections.'.$connection);

        $driver = (string) ($config['driver'] ?? '');
        $database = (string) ($config['database'] ?? '');

        if (config('app.env') === 'testing' && $driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run the test suite against a real database: the "%s" connection points at a %s database ("%s"). '
            .'The suite may only use the in-memory SQLite database, because preparing it drops every table.',
            $connection,
            $driver === '' ? 'unknown' : $driver,
            $database === '' ? 'unknown' : $database,
        ));
    }

    /**
     * Read an environment variable the way Laravel's `env()` does.
     */
    private function environmentValue(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            $value = $_SERVER[$name] ?? ($_ENV[$name] ?? '');
        }

        return trim((string) $value);
    }

    /**
     * A path inside the application, without depending on the booted app.
     */
    private function appPath(string $path): string
    {
        // `__DIR__` is `<app>/tests`, so the application root is its parent.
        return dirname(__DIR__).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
