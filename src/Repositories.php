<?php
declare(strict_types=1);

namespace Gob;

// Tiny registry so call sites don't repeat `new XRepository(Db::conn())`.
// Convention: every repository's constructor takes the shared PDO.
final class Repositories
{
    /** @var array<class-string, object> */
    private static array $cache = [];

    public static function get(string $class): object
    {
        return self::$cache[$class] ??= new $class(Db::conn());
    }

    public static function reset(): void   // for tests
    {
        self::$cache = [];
    }
}
