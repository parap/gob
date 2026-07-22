<?php
declare(strict_types=1);

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

    $cfgFile = dirname(__DIR__) . '/config/config.php';
    if (!is_file($cfgFile)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Server not configured (missing config/config.php).']);
        exit;
    }

    $cfg = require $cfgFile;
    $d   = $cfg['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset=utf8mb4";

    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}
