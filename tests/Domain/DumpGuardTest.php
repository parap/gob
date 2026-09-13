<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\DumpGuard;
use PHPUnit\Framework\TestCase;

// What has to be true before a migration is allowed to touch the deployment. The rule is
// about a dump taken *for this migration*: a directory that merely contains backups proves
// only that somebody once took one, and a column dropped this afternoon is not returned by
// an archive from last night.
final class DumpGuardTest extends TestCase
{
    private const NOW = 1_757_779_000;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dumpguard-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->dir);
    }

    public function testADumpTakenMinutesAgoLetsTheMigrationThrough(): void
    {
        $this->assertNull(
            DumpGuard::refusal('gob', $this->dir, self::NOW - 120, self::NOW)
        );
    }

    public function testAnEmptyBackupDirectoryStopsIt(): void
    {
        $refusal = DumpGuard::refusal('gob', $this->dir, null, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('bin/backup-db.sh', $refusal);
    }

    // The failure this exists to prevent: a directory full of real backups, none of them
    // taken for the change about to run.
    public function testYesterdaysDumpIsNotProtection(): void
    {
        $refusal = DumpGuard::refusal('gob', $this->dir, self::NOW - 86_400, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('1 day old', $refusal);
    }

    public function testTheWindowEndsWhereItSays(): void
    {
        $this->assertNull(
            DumpGuard::refusal('gob', $this->dir, self::NOW - DumpGuard::MAX_AGE, self::NOW),
            'a dump exactly at the limit is still a dump taken for this migration',
        );
        $this->assertNotNull(
            DumpGuard::refusal('gob', $this->dir, self::NOW - DumpGuard::MAX_AGE - 1, self::NOW)
        );
    }

    // Age is what makes a dump count, so a timestamp ahead of the clock cannot be allowed to
    // satisfy the check by arithmetic.
    public function testADumpDatedInTheFutureIsNotADump(): void
    {
        $refusal = DumpGuard::refusal('gob', $this->dir, self::NOW + 60, self::NOW);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('future', $refusal);
    }

    // The suite migrates its own throwaway schema on every run and no dump of it will ever
    // exist. A guard that did not know the difference would refuse the project's own tests.
    public function testAThrowawayTestSchemaIsNeverGuarded(): void
    {
        $this->assertNull(DumpGuard::refusal('gob_test', $this->dir, null, self::NOW));
        $this->assertNull(DumpGuard::refusal('gob_test', $this->dir, self::NOW - 86_400, self::NOW));
    }

    public function testTheNewestDumpIsTheOneThatCounts(): void
    {
        $this->writeDump('nightly-old.sql.gz', self::NOW - 86_400);
        $this->writeDump('pre-deploy-new.sql.gz', self::NOW - 60);
        $this->writeDump('nightly-middle.sql.gz', self::NOW - 3_600);

        $this->assertSame(self::NOW - 60, DumpGuard::latestDumpTime($this->dir));
    }

    // backup-db.sh writes under a name no restore would reach for and renames it only once
    // the archive has been read back and found whole. A file that failed those checks is
    // kept for inspection, and counting it would let the one dump known to be broken stand
    // in for the protection.
    public function testAFileThatIsNotAFinishedDumpDoesNotCount(): void
    {
        $this->writeDump('pre-deploy-broken.sql.gz.rejected', self::NOW - 60);
        $this->writeDump('.pre-deploy-halfway.partial', self::NOW - 60);
        $this->writeDump('notes.txt', self::NOW - 60);

        $this->assertNull(DumpGuard::latestDumpTime($this->dir));
    }

    public function testAnAbsentDirectoryReadsAsNoDump(): void
    {
        $this->assertNull(DumpGuard::latestDumpTime($this->dir . '/nowhere'));
    }

    private function writeDump(string $name, int $mtime): void
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, 'x');
        touch($path, $mtime);
    }
}
