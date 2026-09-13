<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Monster;
use Gob\Domain\Province;
use Gob\Domain\Settlement;
use Gob\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

// The small classes whose whole job is turning a database row into the JSON the
// client reads. Their risk is quiet mis-mapping, so each field is pinned.
final class PayloadTest extends TestCase
{
    public function testAMonsterIsReportedWithItsCombatNumbersAsIntegers(): void
    {
        $m = (new Monster(Fixtures::monsterRow([
            'id' => '5', 'level' => '3', 'hp' => '90', 'attack' => '12',
            'defense' => '4', 'protection' => '2', 'penetration' => '2', 'reward_gold' => '60',
        ])))->toArray();

        $this->assertSame(5, $m['id']);
        $this->assertSame(3, $m['level']);
        $this->assertSame(90, $m['hp']);
        $this->assertSame(12, $m['attack']);
        $this->assertSame(4, $m['defense']);
        $this->assertSame(2, $m['protection']);
        $this->assertSame(2, $m['penetration']);
        $this->assertSame(60, $m['reward_gold']);
    }

    // The three axes are separate: race is the people, nature decides whether
    // anyone can be spared, nation is where the name places them.
    public function testTheThreeIdentityAxesAreReportedSeparately(): void
    {
        $m = (new Monster(Fixtures::monsterRow([
            'race' => 'human', 'nature' => 'mortal', 'nation' => 'Ulm',
        ])))->toArray();

        $this->assertSame('human', $m['race']);
        $this->assertSame('mortal', $m['nature']);
        $this->assertSame('Ulm', $m['nation']);
    }

    public function testAMonsterWhoseIdentityIsUnrecordedGetsSafeDefaults(): void
    {
        $row = Fixtures::monsterRow();
        unset($row['race'], $row['nature'], $row['nation'], $row['alignment']);

        $m = (new Monster($row))->toArray();

        $this->assertSame('unknown', $m['race']);
        $this->assertSame('mortal', $m['nature']);
        $this->assertNull($m['nation']);
        $this->assertSame('neutral', $m['alignment']);
    }

    public function testAMonstersTagsRideAlongside(): void
    {
        $m = (new Monster(Fixtures::monsterRow(), ['evil', 'goblin', 'humanoid']))->toArray();

        $this->assertSame(['evil', 'goblin', 'humanoid'], $m['tags']);
    }

    public function testAMonsterWithNoTagsListsNone(): void
    {
        $this->assertSame([], (new Monster(Fixtures::monsterRow()))->toArray()['tags']);
    }

    public function testTheRawRowStaysReachableForCombat(): void
    {
        $row = Fixtures::monsterRow();

        $this->assertSame($row, (new Monster($row))->row());
    }

    public function testAProvinceKnowsWhetherItIsHome(): void
    {
        $row = ['id' => 1, 'name' => 'Red Downs', 'terrain' => 'plains', 'level' => 1,
                'is_home' => 1, 'explored_pct' => '2.50'];

        $this->assertTrue((new Province($row))->toArray()['is_home']);
        $this->assertFalse((new Province(['is_home' => 0] + $row))->toArray()['is_home']);
    }

    // Where the hero is standing is not a property of the province row — it is
    // passed in, because only one province can be current at a time.
    public function testWhereTheHeroStandsIsToldToTheProvinceNotStoredOnIt(): void
    {
        $row = ['id' => 1, 'name' => 'Red Downs', 'terrain' => 'plains', 'level' => 1,
                'is_home' => 1, 'explored_pct' => '0.00'];

        $this->assertTrue((new Province($row, true))->toArray()['is_current']);
        $this->assertFalse((new Province($row))->toArray()['is_current']);
    }

    public function testExplorationIsReportedAsAFraction(): void
    {
        $row = ['id' => 1, 'name' => 'Red Downs', 'terrain' => 'plains', 'level' => 1,
                'is_home' => 1, 'explored_pct' => '2.50'];

        $this->assertSame(2.5, (new Province($row))->toArray()['explored_pct']);
    }

    public function testASettlementReportsEachStoreAgainstItsOwnCap(): void
    {
        $s = (new Settlement([
            'id' => 1, 'name' => 'Home', 'terrain' => 'plains',
            'gold' => '120', 'wood' => '30', 'stone' => '5',
            'rate_gold_per_hour' => '10', 'rate_wood_per_hour' => '4', 'rate_stone_per_hour' => '1',
            'capacity_gold' => '1000', 'capacity_wood' => '500', 'capacity_stone' => '250',
        ]))->toArray();

        $this->assertSame(120, $s['gold']);
        $this->assertSame(30, $s['wood']);
        $this->assertSame(5, $s['stone']);
        $this->assertSame(10, $s['rate_gold_per_hour']);
        $this->assertSame(4, $s['rate_wood_per_hour']);
        $this->assertSame(1, $s['rate_stone_per_hour']);
        $this->assertSame(1000, $s['capacity_gold']);
        $this->assertSame(500, $s['capacity_wood']);
        $this->assertSame(250, $s['capacity_stone']);
    }

    // Reputation is bookkeeping, not something the settlement panel shows.
    public function testInternalColumnsDoNotReachTheClient(): void
    {
        $s = (new Settlement([
            'id' => 1, 'name' => 'Home', 'terrain' => 'plains', 'reputation' => 40,
            'gold' => 0, 'wood' => 0, 'stone' => 0,
            'rate_gold_per_hour' => 0, 'rate_wood_per_hour' => 0, 'rate_stone_per_hour' => 0,
            'capacity_gold' => 0, 'capacity_wood' => 0, 'capacity_stone' => 0,
        ]))->toArray();

        $this->assertArrayNotHasKey('reputation', $s);
    }
}
