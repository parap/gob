<?php
declare(strict_types=1);

// Resolve DB settings. Environment variables win (Docker / production);
// otherwise fall back to config/config.php for bare-metal development.
function dbConfig(): array
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

// Returns a shared PDO connection, configured safely:
//  - ERRMODE_EXCEPTION: failed queries throw instead of failing silently
//  - EMULATE_PREPARES false: use the database's real prepared statements
//  - FETCH_ASSOC: rows come back as clean associative arrays
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $c   = dbConfig();
    $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";

    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
