<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Repository\SettlementRepository;
use Gob\Tests\Support\DatabaseTestCase;

// The purse, and the rates-plus-timestamp accrual that fills it. Everything
// that touches gold settles production first, so a payment made after an hour
// away spends the hour's earnings too.
final class SettlementRepositoryTest extends DatabaseTestCase
{
    private SettlementRepository $repo;
    private int $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = new SettlementRepository($this->db);
        $this->player = $this->makePlayer('settle');
        $this->repo->createStarting($this->player);
    }

    private function set(array $fields): void
    {
        $sets = implode(', ', array_map(fn(string $k) => "$k = :$k", array_keys($fields)));
        $stmt = $this->db->prepare("UPDATE settlements SET $sets WHERE player_id = :pid");
        $stmt->execute($fields + ['pid' => $this->player]);
    }

    private function row(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM settlements WHERE player_id = ?');
        $stmt->execute([$this->player]);
        return $stmt->fetch();
    }

    public function testANewPlayerGetsExactlyOneSettlement(): void
    {
        $this->assertCount(1, $this->repo->forPlayer($this->player));
        $this->assertNotNull($this->repo->homeId($this->player));
        $this->assertSame('plains', $this->repo->homeTerrain($this->player));
    }

    public function testAPlayerWithNoSettlementHasNoHome(): void
    {
        $other = $this->makePlayer('homeless');

        $this->assertNull($this->repo->homeId($other));
        $this->assertNull($this->repo->homeTerrain($other));
        $this->assertSame(0, $this->repo->gold($other));
    }

    public function testGoldCreditedShowsUpInThePurse(): void
    {
        $this->set(['gold' => 100, 'rate_gold_per_hour' => 0]);

        $this->repo->addGold($this->player, 50);

        $this->assertSame(150, $this->repo->gold($this->player));
    }

    public function testCreditingNothingChangesNothing(): void
    {
        $this->set(['gold' => 100, 'rate_gold_per_hour' => 0]);

        $this->repo->addGold($this->player, 0);
        $this->repo->addGold($this->player, -20);

        $this->assertSame(100, $this->repo->gold($this->player));
    }

    // A store that is already full stays full: the cap is what the settlement
    // can hold, not a target to overshoot.
    public function testAFullStoreCannotBeOverfilled(): void
    {
        $this->set(['gold' => 990, 'capacity_gold' => 1000, 'rate_gold_per_hour' => 0]);

        $this->repo->addGold($this->player, 500);

        $this->assertSame(1000, $this->repo->gold($this->player));
    }

    public function testSpendingTakesTheGoldAndReportsSuccess(): void
    {
        $this->set(['gold' => 100, 'rate_gold_per_hour' => 0]);

        $this->assertTrue($this->repo->spendGold($this->player, 40));
        $this->assertSame(60, $this->repo->gold($this->player));
    }

    // Refusing must change nothing: a partial spend would leave the player
    // poorer with nothing bought.
    public function testAPurchaseTheresNoGoldForChangesNothingAtAll(): void
    {
        $this->set(['gold' => 30, 'rate_gold_per_hour' => 0]);

        $this->assertFalse($this->repo->spendGold($this->player, 40));
        $this->assertSame(30, $this->repo->gold($this->player));
    }

    public function testSpendingExactlyEverythingIsAllowed(): void
    {
        $this->set(['gold' => 40, 'rate_gold_per_hour' => 0]);

        $this->assertTrue($this->repo->spendGold($this->player, 40));
        $this->assertSame(0, $this->repo->gold($this->player));
    }

    public function testSpendingNothingIsFree(): void
    {
        $this->set(['gold' => 0, 'rate_gold_per_hour' => 0]);

        $this->assertTrue($this->repo->spendGold($this->player, 0));
    }

    public function testAPlayerWithNoSettlementCanBuyNothing(): void
    {
        $other = $this->makePlayer('pauper');

        $this->assertFalse($this->repo->spendGold($other, 10));
    }

    // Production catches up whenever the settlement is read, so time away is
    // worth something without a scheduler.
    public function testResourcesCatchUpForTheTimeSinceTheLastRead(): void
    {
        $this->set([
            'gold' => 0, 'rate_gold_per_hour' => 60, 'capacity_gold' => 1000,
            'last_tick' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $this->assertSame(120, $this->repo->gold($this->player));
    }

    public function testAccrualStopsAtTheCap(): void
    {
        $this->set([
            'gold' => 0, 'rate_gold_per_hour' => 10000, 'capacity_gold' => 500,
            'last_tick' => date('Y-m-d H:i:s', time() - 7200),
        ]);

        $this->assertSame(500, $this->repo->gold($this->player));
    }

    // Reading twice in a row must not pay twice: the tick is what moves the
    // clock forward, and it persists as it goes.
    public function testReadingTwiceDoesNotPayTwice(): void
    {
        $this->set([
            'gold' => 0, 'rate_gold_per_hour' => 60, 'capacity_gold' => 1000,
            'last_tick' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $first  = $this->repo->gold($this->player);
        $second = $this->repo->gold($this->player);

        $this->assertSame(60, $first);
        $this->assertSame(60, $second);
    }

    public function testEarningsSinceTheLastReadCanPayForThePurchase(): void
    {
        $this->set([
            'gold' => 0, 'rate_gold_per_hour' => 100, 'capacity_gold' => 1000,
            'last_tick' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $this->assertTrue($this->repo->spendGold($this->player, 80));
        $this->assertSame(20, $this->repo->gold($this->player));
    }

    public function testEveryStoreAccruesAtItsOwnRate(): void
    {
        $this->set([
            'gold' => 0, 'wood' => 0, 'stone' => 0,
            'rate_gold_per_hour' => 10, 'rate_wood_per_hour' => 4, 'rate_stone_per_hour' => 1,
            'capacity_gold' => 1000, 'capacity_wood' => 1000, 'capacity_stone' => 1000,
            'last_tick' => date('Y-m-d H:i:s', time() - 3600),
        ]);

        $after = $this->repo->tick($this->row());

        $this->assertSame(10, (int)$after['gold']);
        $this->assertSame(4, (int)$after['wood']);
        $this->assertSame(1, (int)$after['stone']);
    }

    public function testRatesRiseAndCanNeverGoNegative(): void
    {
        $this->set(['rate_gold_per_hour' => 5, 'rate_wood_per_hour' => 0]);

        $this->repo->adjustRates($this->player, 3, -10, 0);

        $row = $this->row();
        $this->assertSame(8, (int)$row['rate_gold_per_hour']);
        $this->assertSame(0, (int)$row['rate_wood_per_hour']);
    }

    public function testReputationIsKeptOnTheHomeSettlement(): void
    {
        $this->assertSame(0, $this->repo->reputation($this->player));

        $this->repo->addReputation($this->player, 10);
        $this->repo->addReputation($this->player, 0);

        $this->assertSame(10, $this->repo->reputation($this->player));
    }

    public function testANewSettlementStartsStockedRatherThanEmpty(): void
    {
        $fresh = $this->makePlayer('fresh');
        $this->repo->createStarting($fresh);

        $this->assertGreaterThan(0, $this->repo->gold($fresh));
    }

    public function testOnePlayersPurseIsInvisibleToAnother(): void
    {
        $other = $this->makePlayer('neighbour');
        $this->repo->createStarting($other);
        $before = $this->repo->gold($other);

        $this->set(['gold' => 5000, 'rate_gold_per_hour' => 0]);
        $this->repo->spendGold($this->player, 1000);

        $this->assertSame($before, $this->repo->gold($other));
        $this->assertSame(4000, $this->repo->gold($this->player));
    }
}
