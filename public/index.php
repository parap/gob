<?php
declare(strict_types=1);

// Front controller. Handles /api/* as JSON; everything else is the SPA shell.
require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/handlers/auth.php';
require dirname(__DIR__) . '/src/handlers/settlements.php';
require dirname(__DIR__) . '/src/handlers/character.php';
require dirname(__DIR__) . '/src/handlers/items.php';
require dirname(__DIR__) . '/src/handlers/loot.php';
require dirname(__DIR__) . '/src/handlers/combat.php';

$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Dev only: when run via `php -S`, serve real static files directly.
// (In production Caddy serves static files before PHP is ever reached.)
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}

if (str_starts_with($path, '/api/')) {
    try {
        match ("$method $path") {
            'POST /api/auth/register' => handleRegister(),
            'POST /api/auth/login'    => handleLogin(),
            'POST /api/auth/logout'   => handleLogout(),
            'GET /api/settlements/me' => handleMySettlements(),
            'GET /api/character/me'   => handleMyCharacter(),
            'POST /api/items/equip'   => handleEquip(),
            'POST /api/items/unequip' => handleUnequip(),
            'POST /api/loot/search'   => handleLootSearch(),
            'GET /api/monsters'       => handleMonsters(),
            'POST /api/combat/attack' => handleAttack(),
            default                   => json(404, ['error' => 'Not found.']),
        };
    } catch (Throwable $e) {
        json(500, ['error' => 'Server error.']);
    }
    exit;
}

// Any other path serves the single-page app.
readfile(__DIR__ . '/index.html');
