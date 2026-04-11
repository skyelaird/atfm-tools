<?php

declare(strict_types=1);

namespace Atfm;

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * One-stop bootstrap for both web (public/index.php) and CLI (bin/*).
 *
 * Responsibilities:
 *   - Load composer autoloader
 *   - Load .env (if present)
 *   - Boot Eloquent against the configured MySQL connection
 */
final class Bootstrap
{
    private static bool $booted = false;

    public static function boot(string $rootDir): void
    {
        if (self::$booted) {
            return;
        }

        // .env is optional: values may also come from the real environment
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->safeLoad();
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $_ENV['DB_HOST']     ?? '127.0.0.1',
            'port'      => $_ENV['DB_PORT']     ?? '3306',
            'database'  => $_ENV['DB_DATABASE'] ?? 'atfm',
            'username'  => $_ENV['DB_USERNAME'] ?? 'root',
            'password'  => $_ENV['DB_PASSWORD'] ?? '',
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        self::$booted = true;
    }
}
