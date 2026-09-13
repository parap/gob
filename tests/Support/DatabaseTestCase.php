<?php
declare(strict_types=1);

namespace Gob\Tests\Support;

use Gob\Db;
use Gob\Repositories;
use PDO;
use PHPUnit\Framework\TestCase;

// Base for tests that need real SQL. Repositories are mostly queries, so
// stubbing the PDO would test the stub; these run against a throwaway schema
// instead.
//
// Isolation is by deletion, not by transaction: ItemRepository::equip() opens
// a transaction of its own, and PDO cannot nest one. Everything a player owns
// cascades from the `players` row, so removing the players a test created
// removes everything it touched, while the seeded catalogue — monsters, items,
// authored facts — survives for the next test.
abstract class DatabaseTestCase extends TestCase
{
    private static ?PDO $shared = null;
    protected PDO $db;

    /** @var int[] players created by this test */
    private array $created = [];

    public static function setUpBeforeClass(): void
    {
        self::$shared ??= self::boot();
    }

    protected function setUp(): void
    {
        $this->db = self::$shared;
        Db::set($this->db);
        Repositories::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $playerId) {
            $this->db->prepare('DELETE FROM players WHERE id = ?')->execute([$playerId]);
        }
        $this->created = [];
        Repositories::reset();
        Db::set(null);
    }

    // A player row, remembered so tearDown can take it and everything hanging
    // off it back out again.
    protected function makePlayer(string $name = 'tester'): int
    {
        $unique = $name . '_' . bin2hex(random_bytes(4));
        $this->db->prepare('INSERT INTO players (username, email, password_hash) VALUES (?, ?, ?)')
                 ->execute([$unique, "$unique@example.test", 'x']);
        $id = (int)$this->db->lastInsertId();
        $this->created[] = $id;
        return $id;
    }

    private static function boot(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('DB_PORT') ?: 3306);
        $name = getenv('DB_TEST_NAME') ?: 'gob_test';
        $user = getenv('DB_USER') ?: 'gob';
        $pass = getenv('DB_PASS') ?: '';

        // A suite pointed at the development database would delete rows the
        // developer is using and report itself green either way, so the name
        // is checked rather than trusted.
        if (!str_ends_with($name, '_test')) {
            throw new \RuntimeException("Refusing to run tests against '$name': the test database name must end in _test.");
        }

        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        $live = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($live !== $name) {
            throw new \RuntimeException("Connected to '$live' instead of '$name'.");
        }

        self::loadSchema($pdo);
        return $pdo;
    }

    // The schema is the project's own schema.sql, so a column added there is
    // under test on the next run rather than a year later.
    private static function loadSchema(PDO $pdo): void
    {
        $has = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'monsters'"
        )->fetchColumn();
        if ($has > 0) {
            return;
        }

        $sql = file_get_contents(dirname(__DIR__, 2) . '/schema.sql');
        if ($sql === false) {
            throw new \RuntimeException('Cannot read schema.sql.');
        }
        $pdo->exec($sql);
    }
}
