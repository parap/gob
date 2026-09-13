<?php
declare(strict_types=1);

namespace Gob\Domain;

// Whether a migration is allowed to run: the data it is about to change has a dump behind
// it that was taken for this migration, rather than an archive of unknown age that happens
// to be sitting in the directory.
//
// A migration is the one step of a deploy that git cannot undo. `git reset` returns the
// code; nothing returns a column a migration dropped, or a table it rewrote, and what is in
// those rows -- accounts, characters, the world each player has generated -- exists nowhere
// else. The dump taken minutes earlier is the whole of the protection, so its absence stops
// the migration rather than being noticed afterwards.
final class DumpGuard
{
    // bin/deploy.sh dumps and migrates in one ssh session seconds apart, and the manual
    // sequence is two commands typed in a row, so half an hour never refuses a dump taken
    // on purpose. It does refuse last night's nightly, which is the point: a window wide
    // enough to admit any dump in the directory would guarantee nothing but that the
    // directory is not empty.
    public const MAX_AGE = 1800;

    // The refusal to print, or null when the migration may go ahead.
    public static function refusal(
        string $database,
        string $dumpDir,
        ?int $dumpedAt,
        int $now,
        int $maxAge = self::MAX_AGE,
    ): ?string {
        // The suite builds its schema from schema.sql, runs migrations over it and drops
        // what it created; there is nothing in it to lose and no dump will ever exist for
        // it. Guarding it would mean the project's own tests could not run.
        if (str_ends_with($database, '_test')) {
            return null;
        }

        if ($dumpedAt === null) {
            return self::message($database, $dumpDir, "$dumpDir holds no dump.");
        }

        $age = $now - $dumpedAt;

        // A dump dated in the future says nothing about what the database looked like
        // before this migration, so it is not evidence of protection.
        if ($age < 0) {
            return self::message($database, $dumpDir, 'the newest dump is dated in the future.');
        }

        if ($age > $maxAge) {
            return self::message(
                $database,
                $dumpDir,
                'the newest dump is ' . self::age($age) . ' old.',
            );
        }

        return null;
    }

    // When the newest usable dump in $dumpDir was written, or null if there is none.
    //
    // A dump that failed its own checks is kept under a `.rejected` suffix, which this
    // pattern does not match. backup-db.sh gives a dump its real name only once it has been
    // read back and found whole, so a file that did not get one is not protection no matter
    // how recent it is.
    public static function latestDumpTime(string $dumpDir): ?int
    {
        $newest = null;
        foreach (glob(rtrim($dumpDir, '/') . '/*.sql.gz') ?: [] as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && ($newest === null || $mtime > $newest)) {
                $newest = $mtime;
            }
        }

        return $newest;
    }

    private static function message(string $database, string $dumpDir, string $because): string
    {
        return "Refusing to migrate `$database`: $because\n\n"
            . "Dump first, then migrate:\n\n"
            . "    bin/backup-db.sh pre-deploy \$(git rev-parse --short HEAD)\n\n"
            . "bin/deploy.sh does this itself. A dump kept somewhere else is named by\n"
            . "BACKUP_DIR, which this reads too (currently $dumpDir).\n";
    }

    private static function age(int $seconds): string
    {
        $units = [86400 => 'day', 3600 => 'hour', 60 => 'minute'];
        foreach ($units as $size => $name) {
            if ($seconds >= $size) {
                $n = intdiv($seconds, $size);
                return $n . ' ' . $name . ($n === 1 ? '' : 's');
            }
        }

        return $seconds . ' seconds';
    }
}
