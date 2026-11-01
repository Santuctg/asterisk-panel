<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal service container + application lifecycle manager.
 *
 * Holds the shared services (config, db, auth, view, asterisk) so
 * controllers can reach them without global state.
 */
final class App
{
    private static ?App $instance = null;

    /** @var array<string, callable|string> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    public static function getInstance(): App
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        Config::load(dirname(__DIR__, 2) . '/resources/config');
        Session::start();

        if (Config::get('app.debug', false)) {
            ini_set('display_errors', '1');
        }
    }

    /** Register a service factory (lazy). */
    public function bind(string $id, callable|string $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->instances[$id]);
    }

    /** Resolve a service, creating and caching it on first use. */
    public function make(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id])) {
            // Fall back to treating the id as a class name.
            $this->bindings[$id] = static fn () => new $id();
        }

        $factory = $this->bindings[$id];
        $obj     = is_string($factory) ? new $factory() : $factory($this);

        return $this->instances[$id] = $obj;
    }

    public function config(): Config
    {
        return $this->make(Config::class);
    }

    public function db(): Database
    {
        return $this->make(Database::class);
    }

    public function auth(): Auth
    {
        return $this->make(Auth::class);
    }

    public function view(): View
    {
        return $this->make(View::class);
    }
}
