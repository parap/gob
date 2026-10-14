<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\DumpGuard;
use PHPUnit\Framework\TestCase;

// What has to be true before a migration is allowed to touch the deployment: the caller
// names the dump taken for it. Naming is the whole point of the rule. A directory of
// backups also holds dumps taken for other changes, and one of those is indistinguishable
// by age from a dump taken for this migration -- so a rule that reads the directory is
// satisfied exactly when somebody else dumped recently, which is not protection for the
// change about to run.
final class DumpGuardTest extends TestCase
{
    private const NOW  = 1_757_779_000;
    private const DUMP = '/home/ubuntu/gob/backups/pre-deploy-2026-09-13_160335-cd643ca.sql.gz';

    public function testTheDumpTakenForThisMigrationLetsItThrough(): void
    {
        $this->assertNull(
            DumpGuard::refusal('gob', self::DUMP, self::NOW - 120, self::NOW)
        );
    }

    public function testNamingNothingStopsIt(): void
    {
        $refusal = DumpGuard::refusal('gob', null, null, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('no dump was named', $refusal);
        $this->assertStringContainsString('bin/backup-db.sh', $refusal);
        $this->assertStringContainsString('--dump=', $refusal);
    }

    // The failure the rule exists to prevent, and the one a directory scan cannot see: a
    // real, complete, recent dump that was taken for something else. Here it is caught
    // because nobody could have handed this migration a dump that does not exist.
    public function testAnOldDumpIsNotTheDumpForThisMigration(): void
    {
        $refusal = DumpGuard::refusal('gob', self::DUMP, self::NOW - 86_400, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('1 day old', $refusal);
    }

    public function testTheWindowEndsWhereItSays(): void
    {
        $this->assertNull(
            DumpGuard::refusal('gob', self::DUMP, self::NOW - DumpGuard::MAX_AGE, self::NOW),
            'a dump exactly at the limit was still taken for this migration',
        );
        $this->assertNotNull(
            DumpGuard::refusal('gob', self::DUMP, self::NOW - DumpGuard::MAX_AGE - 1, self::NOW)
        );
    }

    // Age is what makes a dump count, so a timestamp ahead of the clock cannot be allowed
    // to satisfy the check by arithmetic.
    public function testADumpDatedInTheFutureIsNotADump(): void
    {
        $refusal = DumpGuard::refusal('gob', self::DUMP, self::NOW + 60, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('future', $refusal);
    }

    // backup-db.sh writes to a name no restore would reach for and renames it only once the
    // archive has been read back and found whole; one that fails those checks keeps a
    // `.rejected` suffix. Naming either of them is naming the file known not to be a
    // backup, however recent it is.
    public function testAFileThatIsNotAFinishedDumpIsRefusedEvenWhenFresh(): void
    {
        foreach ([self::DUMP . '.rejected', '/home/ubuntu/gob/backups/.pre-deploy.partial'] as $path) {
            $refusal = DumpGuard::refusal('gob', $path, self::NOW - 60, self::NOW);

            $this->assertNotNull($refusal, "$path must not pass as a dump");
            $this->assertStringContainsString('not a finished dump', $refusal);
        }
    }

    // A path that reaches nothing is its own failure, and says so rather than being read as
    // "no dump was named": the two are fixed by different things.
    public function testANamedDumpThatCannotBeReadSaysSo(): void
    {
        $refusal = DumpGuard::refusal('gob', self::DUMP, null, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('cannot be read', $refusal);
    }

    // The refusal is read next to a command line holding a long path, so it names the file
    // rather than repeating the path.
    public function testTheRefusalNamesTheFile(): void
    {
        $refusal = DumpGuard::refusal('gob', self::DUMP, self::NOW - 86_400, self::NOW);

        $this->assertStringContainsString(basename(self::DUMP), (string)$refusal);
    }

    // The suite migrates its own throwaway schema on every run and no dump of it will ever
    // exist. A guard that did not know the difference would refuse the project's own tests.
    public function testAThrowawayTestSchemaIsNeverGuarded(): void
    {
        $this->assertNull(DumpGuard::refusal('gob_test', null, null, self::NOW));
        $this->assertNull(DumpGuard::refusal('gob_test', self::DUMP, self::NOW - 86_400, self::NOW));
        $this->assertNull(DumpGuard::refusal('gob_test', self::DUMP . '.rejected', self::NOW, self::NOW));
    }
}
