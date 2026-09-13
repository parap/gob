<?php
declare(strict_types=1);

/**
 * Applies pending schema migrations.
 *
 *   php bin/migrate.php --dump=FILE   apply what is pending
 *   php bin/migrate.php --status      list applied and pending, change nothing
 *
 * The dump named by --dump is the one taken for this migration; applying without it is
 * refused on any database that is not a throwaway _test schema.
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
// migration does not start without one, and the dump is named rather than looked for: a
// directory holds dumps taken for other changes, and age alone cannot tell those from a
// dump taken for this one. --status is exempt above: it reads the ledger and changes
// nothing.
$dumpDir  = rtrim(getenv('BACKUP_DIR') ?: dirname(__DIR__) . '/backups', '/');
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

$named = null;
foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--dump=')) {
        $named = substr($arg, strlen('--dump='));
        break;
    }
    if ($arg === '--dump') {
        $named = $argv[$i + 1] ?? '';
        break;
    }
}

// backup-db.sh runs on the host and prints a host path; this runs in the container, where
// the checkout is mounted somewhere else, so the path that was handed over may name a file
// this process reaches under a different one.
$dumpPath = ($named === null || $named === '') ? null : $named;
$dumpedAt = null;
$tried    = [];
if ($dumpPath !== null) {
    foreach ([$named, $dumpDir . '/' . basename($named)] as $candidate) {
        $tried[] = $candidate;
        $mtime = is_file($candidate) ? @filemtime($candidate) : false;
        if ($mtime !== false) {
            $dumpPath = $candidate;
            $dumpedAt = $mtime;
            break;
        }
    }
}

$refusal = DumpGuard::refusal($database, $dumpPath, $dumpedAt, time());
if ($refusal !== null) {
    // Named and not found reads exactly like named and rejected unless the paths are said
    // out loud, and the two are fixed by different things.
    if ($dumpPath !== null && $dumpedAt === null) {
        $refusal .= "\nLooked for it at " . implode(' and ', array_unique($tried)) . ".\n";
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
