<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Character;
use Gob\Domain\Language;
use Gob\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

// The hero's derived view: what the paperdoll adds up to, what the sheet is
// willing to say about a tongue, and how long someone lies at your mercy.
final class CharacterTest extends TestCase
{
    private function character(array $row = [], array $skills = [], array $owned = []): Character
    {
        return new Character(Fixtures::characterRow($row), $skills, $owned);
    }

    public function testBareStatsPassThroughUntouched(): void
    {
        $c = $this->character(['strength' => 4, 'dexterity' => 3])->toArray();

        $this->assertSame(4, $c['stats']['str']);
        $this->assertSame(4, $c['stats_effective']['str']);
        $this->assertSame(3, $c['stats']['dex']);
    }

    public function testEquippedGearRaisesAPrimaryStat(): void
    {
        $c = $this->character(
            ['strength' => 2],
            [],
            [Fixtures::ownedItem(['bonuses' => ['str' => 5]])],
        )->toArray();

        $this->assertSame(2, $c['stats']['str'], 'the base stat is untouched');
        $this->assertSame(7, $c['stats_effective']['str']);
    }

    public function testEquippedGearRaisesACombatSubstat(): void
    {
        $c = $this->character([], [], [Fixtures::ownedItem(['bonuses' => ['attack' => 3]])])->toArray();

        $this->assertSame(0, $c['substats']['attack']);
        $this->assertSame(3, $c['substats_effective']['attack']);
    }

    // Gear that grants health raises the ceiling; it does not hand the hero
    // free hit points on the spot.
    public function testHealthGearRaisesTheMaximumNotTheCurrentValue(): void
    {
        $c = $this->character(
            ['hp' => 40, 'hp_max' => 100],
            [],
            [Fixtures::ownedItem(['bonuses' => ['hp' => 10]])],
        )->toArray();

        $this->assertSame(40, $c['vitals']['hp']);
        $this->assertSame(110, $c['vitals']['hp_max']);
    }

    public function testPerceptionIsIntelligencePlusDexterity(): void
    {
        $c = $this->character(['intelligence' => 4, 'dexterity' => 3])->toArray();

        $this->assertSame(7, $c['substats']['perception']);
        $this->assertSame(7, $c['substats_effective']['perception']);
    }

    public function testPerceptionGearAddsOnTopOfTheDerivedValue(): void
    {
        $c = $this->character(
            ['intelligence' => 4, 'dexterity' => 3],
            [],
            [Fixtures::ownedItem(['bonuses' => ['perception' => 2]])],
        )->toArray();

        $this->assertSame(7, $c['substats']['perception'], 'the derived base ignores gear');
        $this->assertSame(9, $c['substats_effective']['perception']);
    }

    // Perception is derived from the *effective* stats, so a ring of wits
    // sharpens the eye through intelligence as well as directly.
    public function testStatGearSharpensPerceptionThroughIntelligence(): void
    {
        $c = $this->character(
            ['intelligence' => 4, 'dexterity' => 3],
            [],
            [Fixtures::ownedItem(['bonuses' => ['int' => 6]])],
        )->toArray();

        $this->assertSame(13, $c['substats_effective']['perception']);
    }

    public function testWornGearFillsItsSlotAndStaysOutOfTheBackpack(): void
    {
        $c = $this->character([], [], [Fixtures::ownedItem(['equipped_slot' => 'head'])])->toArray();

        $this->assertNotNull($c['equipment']['head']);
        $this->assertSame([], $c['inventory']);
    }

    public function testCarriedGearGoesToTheBackpackAndFillsNoSlot(): void
    {
        $c = $this->character([], [], [Fixtures::ownedItem(['equipped_slot' => null])])->toArray();

        $this->assertCount(1, $c['inventory']);
        $this->assertNull($c['equipment']['weapon']);
    }

    public function testEverySlotIsPresentEvenWhenEmpty(): void
    {
        $c = $this->character()->toArray();

        $this->assertSame(Character::EQUIPMENT_SLOTS, array_keys($c['equipment']));
    }

    public function testRegenerationBonusAddsToTheBaseRate(): void
    {
        $c = $this->character(['regen_bonus' => 15])->toArray();

        $this->assertSame(Character::HP_REGEN_PER_MIN + 15, $c['vitals']['hp_regen_per_min']);
    }

    // A tongue is reported as comprehension, never as the integer behind it —
    // a language is either understood or not, unlike a weapon skill (§1).
    public function testATongueIsReportedInWordsWithNoNumberAttached(): void
    {
        $c = $this->character([], ['lang_goblin' => 12, 'sword' => 40])->toArray();

        $this->assertSame(
            [['skill' => 'lang_goblin', 'label' => 'Goblin tongue', 'fluency' => 'broken']],
            $c['languages'],
        );
        $this->assertSame(40, $c['skills']['sword'], 'weapon skills stay numbers');
    }

    public function testATongueNobodyTaughtIsNotListed(): void
    {
        $c = $this->character([], ['lang_goblin' => 0])->toArray();

        $this->assertSame([], $c['languages']);
    }

    public function testAnEnemyLyingAtYourMercyIsReportedWithTimeLeft(): void
    {
        $c = $this->character([
            'spared_monster_id' => 7,
            'spared_at'         => date('Y-m-d H:i:s', time() - 5),
        ])->toArray();

        $this->assertSame(7, $c['mercy_window']['monster_id']);
        $this->assertGreaterThan(0, $c['mercy_window']['seconds_left']);
        $this->assertLessThanOrEqual(Character::MERCY_WINDOW_SECONDS, $c['mercy_window']['seconds_left']);
    }

    public function testAMercyWindowThatRanOutIsNotReported(): void
    {
        $c = $this->character([
            'spared_monster_id' => 7,
            'spared_at'         => date('Y-m-d H:i:s', time() - Character::MERCY_WINDOW_SECONDS - 1),
        ])->toArray();

        $this->assertNull($c['mercy_window']);
    }

    public function testNobodyAtYourMercyMeansNoWindow(): void
    {
        $this->assertNull($this->character()->toArray()['mercy_window']);
    }

    // A lesson reports where it will leave you, never by how much — the size of
    // the gain is exactly the number that must not cross the wire (§1).
    public function testALessonInProgressNamesItsDestinationNotItsSize(): void
    {
        $t = $this->character(
            [
                'training_skill'   => 'lang_goblin',
                'training_gain'    => 23,
                'training_ends_at' => date('Y-m-d H:i:s', time() + 60),
            ],
            ['lang_goblin' => 8],
        )->toArray()['training'];

        $this->assertSame('lang_goblin', $t['skill']);
        $this->assertSame('Goblin tongue', $t['label']);
        $this->assertSame(Language::fluency(31), $t['toward']);
        $this->assertGreaterThan(0, $t['seconds_left']);
        $this->assertArrayNotHasKey('gain', $t);
    }

    public function testAWeaponLessonHasNoFluencyToAimAt(): void
    {
        $t = $this->character([
            'training_skill'   => 'sword',
            'training_gain'    => 5,
            'training_ends_at' => date('Y-m-d H:i:s', time() + 60),
        ], ['sword' => 10])->toArray()['training'];

        $this->assertSame('sword', $t['label']);
        $this->assertNull($t['toward']);
    }

    public function testAnOverdueLessonReportsNoTimeLeftRatherThanNegative(): void
    {
        $t = $this->character([
            'training_skill'   => 'sword',
            'training_gain'    => 5,
            'training_ends_at' => date('Y-m-d H:i:s', time() - 120),
        ], ['sword' => 10])->toArray()['training'];

        $this->assertSame(0, $t['seconds_left']);
    }

    public function testNoLessonMeansNoTrainingBlock(): void
    {
        $this->assertNull($this->character()->toArray()['training']);
    }

    public function testTheMercyStanceIsReportedAsABoolean(): void
    {
        $this->assertTrue($this->character(['mercy' => 1])->toArray()['mercy']);
        $this->assertFalse($this->character(['mercy' => 0])->toArray()['mercy']);
    }
}
