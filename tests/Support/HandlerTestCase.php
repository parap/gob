<?php
declare(strict_types=1);

namespace Gob\Tests\Support;

// Base for tests of the procedural handler layer. The handlers are plain
// functions in required files rather than classes, so they are loaded once here
// the same way the front controller loads them.
//
// Only the functions that return a value are reachable from a test: anything
// that answers the request through json() calls exit, which would take the test
// runner with it. Those are the routes, and they are covered end to end through
// the running app instead.
abstract class HandlerTestCase extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $src = dirname(__DIR__, 2) . '/src';
        require_once "$src/db.php";
        require_once "$src/helpers.php";
        foreach (['auth', 'settlements', 'character', 'items', 'loot', 'combat', 'world', 'village', 'training', 'perception'] as $handler) {
            require_once "$src/handlers/$handler.php";
        }
    }
}
