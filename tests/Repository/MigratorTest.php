<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Migrator;
use Gob\Tests\Support\DatabaseTestCase;

// schema.sql is mounted into MySQL's init directory, which runs only on a volume that does
// not yet exist. Every schema change after the first deploy therefore has to arrive some
// other way, and this is it: forward-only files applied once and recorded.
//
// Run against the real test database, because what is being checked is that MySQL accepted
// the DDL and that the record of it survives — neither of which a stubbed PDO can answer.
final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/gobmig_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->db->exec('DROP TABLE IF EXISTS schema_migrations');
        $this->db->exec('DROP TABLE IF EXISTS mig_probe');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        $this->db->exec('DROP TABLE IF EXISTS schema_migrations');
        $this->db->exec('DROP TABLE IF EXISTS mig_probe');
        parent::tearDown();
    }

    private function write(string $name, string $sql): void
    {
        file_put_contents($this->dir . '/' . $name, $sql);
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }

    public function testAppliesAPendingMigrationAndRecordsIt(): void
    {
        $this->write('0001_probe.sql', 'CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;');

        $applied = Migrator::run($this->db, $this->dir);

        $this->assertSame(['0001_probe'], $applied);
        $this->assertTrue($this->tableExists('mig_probe'));
    }

    // The whole point. A migration that runs twice is a migration that fails the second
    // time, on the deploy after the one that introduced it.
    public function testRunningAgainAppliesNothing(): void
    {
        $this->write('0001_probe.sql', 'CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;');
        Migrator::run($this->db, $this->dir);

        $this->assertSame([], Migrator::run($this->db, $this->dir));
    }

    // Ordered by filename, not by whatever order the filesystem hands them back, because a
    // later migration routinely depends on an earlier one.
    public function testAppliesInFilenameOrder(): void
    {
        $this->write('0002_second.sql', 'ALTER TABLE mig_probe ADD COLUMN name VARCHAR(10) NULL;');
        $this->write('0001_first.sql', 'CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;');

        $this->assertSame(['0001_first', '0002_second'], Migrator::run($this->db, $this->dir));
    }

    // A migration recorded as applied when it in fact failed is the worst outcome
    // available: the next run skips it, and the schema is wrong from then on with nothing
    // saying so.
    public function testAFailedMigrationIsNotRecorded(): void
    {
        $this->write('0001_broken.sql', 'CREATE TABLE mig_probe (this is not sql);');

        try {
            Migrator::run($this->db, $this->dir);
            $this->fail('a broken migration should not have been accepted');
        } catch (\Throwable) {
            // expected
        }

        $done = $this->db->query('SELECT version FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], $done);
    }

    // A later migration must not be reached once an earlier one has failed, or the schema
    // ends up in a state no single file describes.
    public function testAFailureStopsTheRun(): void
    {
        $this->write('0001_broken.sql', 'CREATE TABLE mig_probe (this is not sql);');
        $this->write('0002_after.sql', 'CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;');

        try {
            Migrator::run($this->db, $this->dir);
        } catch (\Throwable) {
        }

        $this->assertFalse($this->tableExists('mig_probe'));
    }

    public function testSeveralStatementsInOneFileAllApply(): void
    {
        $this->write('0001_two.sql', "CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;\n"
                                   . "ALTER TABLE mig_probe ADD COLUMN name VARCHAR(10) NULL;\n");

        Migrator::run($this->db, $this->dir);

        $cols = $this->db->query('SHOW COLUMNS FROM mig_probe')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['id', 'name'], $cols);
    }

    public function testCommentsAndBlankLinesAreNotStatements(): void
    {
        $this->write('0001_commented.sql', "-- add the probe table\n\n"
                                         . "CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;\n\n"
                                         . "-- trailing note\n");

        $this->assertSame(['0001_commented'], Migrator::run($this->db, $this->dir));
        $this->assertTrue($this->tableExists('mig_probe'));
    }

    public function testPendingListsWhatHasNotRunYet(): void
    {
        $this->write('0001_probe.sql', 'CREATE TABLE mig_probe (id INT PRIMARY KEY) ENGINE=InnoDB;');
        $this->write('0002_later.sql', 'ALTER TABLE mig_probe ADD COLUMN name VARCHAR(10) NULL;');

        $this->assertSame(['0001_probe', '0002_later'], Migrator::pending($this->db, $this->dir));
        Migrator::run($this->db, $this->dir);
        $this->assertSame([], Migrator::pending($this->db, $this->dir));
    }

    // An empty directory is the normal state of a project that has not needed a migration
    // yet, and must not be an error on every deploy.
    public function testAnEmptyDirectoryIsFine(): void
    {
        $this->assertSame([], Migrator::run($this->db, $this->dir));
    }
}
