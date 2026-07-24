<?php
declare(strict_types=1);

// Front controller. Handles /api/* as JSON; everything else is the SPA shell.

// Composer PSR-4 autoloader (Gob\ => src/). New OOP code loads on demand; the
// legacy procedural handlers below are still require'd explicitly during the
// incremental migration to classes.
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;                       // composer (also loads any deps)
} else {
    // Fallback PSR-4 loader (Gob\ => src/) so the app still runs even if
    // `composer install` hasn't been run. vendor/ is gitignored.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Gob\\';
        if (str_starts_with($class, $prefix)) {
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

require dirname(__DIR__) . '/src/db.php';
require dirname(__DIR__) . '/src/helpers.php';
require dirname(__DIR__) . '/src/handlers/auth.php';
require dirname(__DIR__) . '/src/handlers/settlements.php';
require dirname(__DIR__) . '/src/handlers/character.php';
require dirname(__DIR__) . '/src/handlers/items.php';
require dirname(__DIR__) . '/src/handlers/loot.php';
require dirname(__DIR__) . '/src/handlers/combat.php';
require dirname(__DIR__) . '/src/handlers/world.php';
require dirname(__DIR__) . '/src/handlers/village.php';

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
            'POST /api/items/use'     => handleUseItem(),
            'POST /api/items/sell'    => handleSellItem(),
            'POST /api/loot/search'   => handleLootSearch(),
            'GET /api/monsters'       => handleMonsters(),
            'POST /api/combat/attack' => handleAttack(),
            'GET /api/world'              => handleWorld(),
            'POST /api/world/explore'     => handleProvinceExplore(),
            'POST /api/world/travel'      => handleTravel(),
            'POST /api/world/sites/advance' => handleSiteAdvance(),
            'GET /api/npcs'           => handleNpcs(),
            'GET /api/quests'         => handleQuests(),
            'POST /api/quests/accept' => handleQuestAccept(),
            'POST /api/quests/turn-in'=> handleQuestTurnIn(),
            default                   => json(404, ['error' => 'Not found.']),
        };
    } catch (Throwable $e) {
        json(500, ['error' => 'Server error.']);
    }
    exit;
}

// Any other path serves the single-page app.
readfile(__DIR__ . '/index.html');
