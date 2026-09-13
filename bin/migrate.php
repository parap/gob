<?php
declare(strict_types=1);

/**
 * Applies pending schema migrations.
 *
 *   php bin/migrate.php            apply what is pending
 *   php bin/migrate.php --status   list applied and pending, change nothing
 *
 * schema.sql builds the first database and is never read again — MySQL runs its init
 * directory only while creating a volume that does not exist yet. Everything after that
 * comes from db/migrations/, one forward-only file per change, named so they sort into the
 * order they must run in: 0001_what_it_does.sql.
 *
 * Run from bin/deploy.sh, so a schema change reaches the server with the commit that needs
 * it rather than surfacing as a missing column on somebody's request.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Gob\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = dirname(__DIR__) . '/src/'
              . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use Gob\Db;
use Gob\Domain\DumpGuard;
use Gob\Migrator;

$dir = dirname(__DIR__) . '/db/migrations';
$pdo = Db::conn();

if (in_array('--status', $argv, true)) {
    $pending = array_flip(Migrator::pending($pdo, $dir));
    $all     = array_map(
        static fn(string $f): string => basename($f, '.sql'),
        glob($dir . '/*.sql') ?: [],
    );
    sort($all, SORT_STRING);

    if ($all === []) {
        echo "No migrations yet.\n";
        exit(0);
    }
    foreach ($all as $version) {
        printf("%-8s %s\n", isset($pending[$version]) ? 'PENDING' : 'applied', $version);
    }
    exit(0);
}

// The dump is the only thing between a migration and every account on the server, so a
// migration does not start without one taken for it. --status is exempt above: it reads the
// ledger and changes nothing.
$dumpDir  = getenv('BACKUP_DIR') ?: dirname(__DIR__) . '/backups';
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

$refusal = DumpGuard::refusal($database, $dumpDir, DumpGuard::latestDumpTime($dumpDir), time());
if ($refusal !== null) {
    // A directory this process cannot read looks exactly like an empty one, and the two
    // are fixed by different things.
    if (!is_dir($dumpDir) || !is_readable($dumpDir)) {
        $refusal .= "\n$dumpDir cannot be read from here, so a dump inside it is not seen.\n";
    }
    fwrite(STDERR, "\n" . $refusal);
    exit(1);
}

try {
    $applied = Migrator::run($pdo, $dir);
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n\n"
        . "Nothing after the failing statement ran, and the migration is not recorded,\n"
        . "so repairing the file and running again picks up where this stopped.\n");
    exit(1);
}

echo $applied === []
    ? "Nothing to apply; schema is current.\n"
    : 'Applied ' . count($applied) . ': ' . implode(', ', $applied) . "\n";
