<?php

namespace Tests\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class TestEnvironment
{
    private static ?string $cachePath = null;

    private static ?string $key = null;

    public static function prepare(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('Tests require pdo_sqlite and an in-memory database. No persistent database may be used.');
        }
        self::$cachePath ??= sys_get_temp_dir().'/trademinator-tests-'.bin2hex(random_bytes(16));
        self::$key ??= 'base64:'.base64_encode(random_bytes(32));
        foreach ([
            'APP_ENV' => 'testing', 'APP_KEY' => self::$key,
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'OWNER_QUEUE_CONNECTIONS' => 'database,sync',
            // Baseline tests are deployment-independent; memory tests opt in via config.
            'SIGNAL_MEMORY_ENABLED' => 'false',
            'TICKER_HISTORY_CACHE_ENABLED' => 'false',
            'TICKER_HISTORY_CACHE_STORE' => 'array',
            // Ignore deployment caches without clearing or overwriting them.
            'APP_CONFIG_CACHE' => self::$cachePath.'/config.php',
            'APP_ROUTES_CACHE' => self::$cachePath.'/routes.php',
            'APP_EVENTS_CACHE' => self::$cachePath.'/events.php',
        ] as $name => $value) {
            putenv($name.'='.$value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    /** Check resolved config before providers, database refresh hooks or migrations. */
    public static function guard(Repository $config): void
    {
        $sqlite = $config->get('database.connections.sqlite');
        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || ! is_array($sqlite)
            || ($sqlite['driver'] ?? null) !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || ! in_array($sqlite['url'] ?? null, [null, ''], true)
            || isset($sqlite['read']) || isset($sqlite['write'])) {
            throw new RuntimeException('Unsafe test database configuration: only SQLite :memory: with no DB_URL/read/write overrides is allowed. Stopped before database refresh.');
        }
        // Named connections from the deployment must not remain reachable by tests.
        $config->set('database.connections', ['sqlite' => $sqlite]);
    }
}
