<?php
declare(strict_types=1);

namespace Gob\Domain;

// Whether a migration is allowed to run: the caller has named the dump taken for it.
//
// A migration is the one step of a deploy that git cannot undo. `git reset` returns the
// code; nothing returns a column a migration dropped, or a table it rewrote, and what is in
// those rows -- accounts, characters, the world each player has generated -- exists nowhere
// else. The dump is the whole of the protection, so its absence stops the migration rather
// than being noticed afterwards.
//
// The dump is named rather than searched for. A directory holds dumps taken for other
// changes, and one of those is indistinguishable, by age alone, from a dump taken for this
// one -- so a rule that reads the directory passes whenever somebody else happened to dump
// recently, which is precisely when nobody took a dump for the migration about to run.
// Naming the file cannot happen by accident: the path comes from the run of backup-db.sh
// that produced it.
final class DumpGuard
{
    // The named dump still has to be recent, or a path typed once could be reused forever.
    // bin/deploy.sh dumps and migrates in one ssh session seconds apart, and the manual
    // sequence is two commands typed in a row, so half an hour never refuses a dump that
    // was taken for the migration it is passed to.
    public const MAX_AGE = 1800;

    // A finished dump carries this suffix and nothing else does. backup-db.sh writes to a
    // `.partial` name and renames only once the archive has been read back and found whole;
    // one that fails those checks is kept as `.sql.gz.rejected`. Either would be a file in
    // the directory, and neither is protection.
    public const SUFFIX = '.sql.gz';

    // The refusal to print, or null when the migration may go ahead.
    //
    // $dumpPath is the dump named on the command line, resolved to something readable, or
    // null when nothing was named or what was named is not there. $dumpedAt is its mtime.
    public static function refusal(
        string $database,
        ?string $dumpPath,
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

        if ($dumpPath === null) {
            return self::message($database, 'no dump was named for it.');
        }

        if (!str_ends_with($dumpPath, self::SUFFIX)) {
            return self::message(
                $database,
                basename($dumpPath) . ' is not a finished dump.',
            );
        }

        if ($dumpedAt === null) {
            return self::message(
                $database,
                basename($dumpPath) . ' cannot be read.',
            );
        }

        $age = $now - $dumpedAt;

        // A dump dated in the future says nothing about what the database looked like
        // before this migration, so it is not evidence of protection.
        if ($age < 0) {
            return self::message(
                $database,
                basename($dumpPath) . ' is dated in the future.',
            );
        }

        if ($age > $maxAge) {
            return self::message(
                $database,
                basename($dumpPath) . ' is ' . self::age($age) . ' old, so it was taken for'
                . ' something other than this migration.',
            );
        }

        return null;
    }

    private static function message(string $database, string $because): string
    {
        return "Refusing to migrate `$database`: $because\n\n"
            . "Dump first, then migrate, passing the dump the first command prints:\n\n"
            . "    dump=\$(bin/backup-db.sh pre-deploy \$(git rev-parse --short HEAD))\n"
            . "    docker compose exec -T php php bin/migrate.php --dump=\"\$dump\"\n\n"
            . "bin/deploy.sh does both itself.\n";
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
