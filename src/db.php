<?php
declare(strict_types=1);

// Backward-compatible shim. The connection now lives in Gob\Db; this global
// wrapper keeps the existing db() call sites working during the migration.
function db(): PDO
{
    return \Gob\Db::conn();
}
