<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Item;
use Gob\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

// Items as definitions and as owned instances, plus the rarity-weighted roll
// that decides what a body drops.
final class ItemTest extends TestCase
{
    public function testMostThingsHaveExactlyOneSlot(): void
    {
        $this->assertSame(['head'], Item::slotsForType('head'));
        $this->assertSame(['weapon'], Item::slotsForType('weapon'));
    }

    public function testRingsAndBraceletsComeInPairs(): void
    {
        $this->assertSame(['ring_1', 'ring_2'], Item::slotsForType('ring'));
        $this->assertSame(['bracelet_1', 'bracelet_2'], Item::slotsForType('bracelet'));
    }

    public function testABriefViewListsOnlyTheBonusesThatDoSomething(): void
    {
        $brief = Item::brief(Fixtures::itemRow(['bonus_attack' => 3, 'bonus_defense' => 0]));

        $this->assertSame(['attack' => 3], $brief['bonuses']);
    }

    public function testABriefViewCarriesTheDefinitionId(): void
    {
        $brief = Item::brief(Fixtures::itemRow(['id' => 19, 'name' => 'Goblin Ear']));

        $this->assertSame(19, $brief['item_id']);
        $this->assertSame('Goblin Ear', $brief['name']);
    }

    public function testNegativeBonusesAreKeptBecauseCursedGearIsStillGear(): void
    {
        $brief = Item::brief(Fixtures::itemRow(['bonus_dex' => -2]));

        $this->assertSame(['dex' => -2], $brief['bonuses']);
    }

    public function testAnOwnedInstanceCarriesBothItsOwnIdAndItsDefinitionId(): void
    {
        $owned = (new Item([
            'ci_id' => 42, 'item_id' => 3, 'name' => 'Leather Cap', 'slot_type' => 'head',
            'rarity' => 'common', 'weapon_skill' => null, 'equipped_slot' => 'head',
            'kind' => 'gear', 'heal_hp' => 0, 'sell_value' => 10, 'description' => '',
        ] + array_fill_keys(array_map(fn($k) => "bonus_$k", Item::BONUS_KEYS), 0)))->toArray();

        $this->assertSame(42, $owned['char_item_id']);
        $this->assertSame(3, $owned['item_id']);
        $this->assertSame('head', $owned['equipped_slot']);
    }

    // The weighted roll is what makes an epic feel like an epic. Over enough
    // rolls the common must dominate by roughly its weight.
    public function testRarerThingsDropLessOften(): void
    {
        $items = [
            Fixtures::itemRow(['id' => 1, 'rarity' => 'common']),
            Fixtures::itemRow(['id' => 2, 'rarity' => 'epic']),
        ];

        $counts = [1 => 0, 2 => 0];
        for ($i = 0; $i < 3000; $i++) {
            $counts[(int)Item::pickWeighted($items)['id']]++;
        }

        $this->assertGreaterThan($counts[2] * 10, $counts[1]);
        $this->assertGreaterThan(0, $counts[2], 'an epic still drops sometimes');
    }

    public function testASingleCandidateIsAlwaysWhatDrops(): void
    {
        $only = [Fixtures::itemRow(['id' => 7, 'rarity' => 'rare'])];

        $this->assertSame(7, (int)Item::pickWeighted($only)['id']);
    }

    public function testAnUnknownRarityStillGetsAChance(): void
    {
        $items = [
            Fixtures::itemRow(['id' => 1, 'rarity' => 'mythic']),
            Fixtures::itemRow(['id' => 2, 'rarity' => 'mythic']),
        ];

        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[(int)Item::pickWeighted($items)['id']] = true;
        }

        $keys = array_keys($seen);
        sort($keys);
        $this->assertSame([1, 2], $keys);
    }

    public function testTheRarityLadderOnlyGetsSteeper(): void
    {
        $weights = array_values(Item::RARITY_WEIGHTS);
        $sorted  = $weights;
        rsort($sorted);

        $this->assertSame($sorted, $weights, 'rarities are listed commonest first');
        $this->assertSame(count(array_unique($weights)), count($weights));
    }
}
