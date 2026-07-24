<?php
declare(strict_types=1);

namespace Gob;

use PDO;

// The shared database connection. Config comes from environment variables
// (Docker / production) or config/config.php (bare-metal dev).
final class Db
{
    private static ?PDO $pdo = null;

    // The shared PDO connection (opened once per request).
    public static function conn(): PDO
    {
        return self::$pdo ??= self::connect();
    }

    // Override / reset the connection (e.g. for tests).
    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    private static function config(): array
    {
        if (getenv('DB_HOST') !== false) {
            return [
                'host' => getenv('DB_HOST'),
                'port' => (int)(getenv('DB_PORT') ?: 3306),
                'name' => getenv('DB_NAME') ?: 'gob',
                'user' => getenv('DB_USER') ?: 'gob',
                'pass' => getenv('DB_PASS') ?: '',
            ];
        }

        $cfgFile = dirname(__DIR__) . '/config/config.php';
        if (!is_file($cfgFile)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Server not configured (no DB_HOST env and no config/config.php).']);
            exit;
        }
        return (require $cfgFile)['db'];
    }

    // Configured safely: exceptions on error, real prepared statements,
    // associative-array rows.
    private static function connect(): PDO
    {
        $c   = self::config();
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";

        return new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
