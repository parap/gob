<?php
declare(strict_types=1);

namespace Gob;

use PDO;
use RuntimeException;
use Throwable;

// Forward-only schema migrations.
//
// schema.sql is mounted into MySQL's init directory, which runs only while creating a
// volume that does not exist yet. It builds the first database and is never consulted
// again, so every change after that arrives as a file here: applied once, in filename
// order, and recorded so the next deploy skips it.
final class Migrator
{
    // Applies every pending migration in $dir and returns the versions applied, oldest
    // first. Throws on the first failure without recording it.
    public static function run(PDO $pdo, string $dir): array
    {
        self::ensureLedger($pdo);
        $applied = [];

        foreach (self::pending($pdo, $dir) as $version) {
            $file = $dir . '/' . $version . '.sql';
            $sql  = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration $version.");
            }

            $statements = self::statements($sql);

            // No wrapping transaction, and not by oversight: MySQL commits implicitly on
            // every DDL statement, so a transaction cannot roll a failed migration back.
            // It would only make commit() fail afterwards and bury the SQL error that
            // actually matters. A migration that fails is repaired by hand.
            foreach ($statements as $i => $statement) {
                try {
                    $pdo->exec($statement);
                } catch (Throwable $e) {
                    throw new RuntimeException(
                        "Migration $version failed on statement #$i: " . $e->getMessage()
                        . "\n" . trim(substr($statement, 0, 200)),
                        0,
                        $e,
                    );
                }
            }

            // Recorded only once every statement landed. A version written down for a
            // migration that did not finish is the worst outcome available: the next run
            // skips it and the schema stays wrong with nothing saying so.
            $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $applied[] = $version;
        }

        return $applied;
    }

    // The versions present in $dir that the ledger has no row for, in filename order.
    public static function pending(PDO $pdo, string $dir): array
    {
        self::ensureLedger($pdo);

        $done = array_flip(
            $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
        );

        $versions = array_map(
            static fn(string $f): string => basename($f, '.sql'),
            glob($dir . '/*.sql') ?: [],
        );
        sort($versions, SORT_STRING);

        return array_values(array_filter(
            $versions,
            static fn(string $v): bool => !isset($done[$v]),
        ));
    }

    private static function ensureLedger(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(255) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
             ) ENGINE=InnoDB'
        );
    }

    // Splits a file into statements. Full-line `--` comments go first, then the text is
    // cut on semicolons that end a line. Enough for plain DDL, which is all this project
    // has: there are no stored programs, where a body's own semicolons would need a
    // delimiter to survive.
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        return array_values(array_filter(
            array_map('trim', preg_split('/;\s*[\r\n]|;\s*$/', $sql) ?: []),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
