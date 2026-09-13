<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Relationship;
use PHPUnit\Framework\TestCase;

// Reciprocal mercy (§3): losing a fight is not one flat outcome. What the
// beaten player wakes up to is decided by how the winner's people feel about
// them, so a reputation earned by sparing is paid back in kind.
final class RelationshipLossTest extends TestCase
{
    public function testAPeopleWhoAttackOnSightRobTheDownedPlayer(): void
    {
        $this->assertSame(
            Relationship::LOSS_ROBBED,
            Relationship::lossOutcome(Relationship::STAGE_MONSTER, true, false),
        );
    }

    public function testCuriousIsStillNotEnoughToBeSpared(): void
    {
        $this->assertSame(
            Relationship::LOSS_ROBBED,
            Relationship::lossOutcome(Relationship::STAGE_CURIOUS, true, false),
        );
    }

    public function testNeutralSparesThePlayer(): void
    {
        $this->assertSame(
            Relationship::LOSS_SPARED,
            Relationship::lossOutcome(Relationship::STAGE_NEUTRAL, true, false),
        );
    }

    public function testFriendlyPatchesThePlayerUp(): void
    {
        $this->assertSame(
            Relationship::LOSS_HELPED,
            Relationship::lossOutcome(Relationship::STAGE_FRIENDLY, true, false),
        );
    }

    public function testAlliesPatchThePlayerUp(): void
    {
        $this->assertSame(
            Relationship::LOSS_HELPED,
            Relationship::lossOutcome(Relationship::STAGE_ALLY, true, false),
        );
    }

    // A risen corpse holds no opinion and grants no quarter, whatever the
    // numbers attached to its race say.
    public function testSomethingWithNobodyHomeAlwaysRobs(): void
    {
        $this->assertSame(
            Relationship::LOSS_ROBBED,
            Relationship::lossOutcome(Relationship::STAGE_ALLY, false, false),
        );
    }

    // The mirror of the fanatic roll that denies the player a spare (§3).
    public function testAnEnemyWhoGrantsNoQuarterRobsAnAlly(): void
    {
        $this->assertSame(
            Relationship::LOSS_ROBBED,
            Relationship::lossOutcome(Relationship::STAGE_ALLY, true, true),
        );
    }

    // The thematic payoff, asserted against the constants rather than restated:
    // sparing carries Hostility down to exactly the rung that buys quarter back.
    public function testSparingToItsFloorIsExactlyEnoughToBeSparedInTurn(): void
    {
        $stage = Relationship::stage(
            Relationship::SPARE_HOSTILITY_FLOOR,
            Relationship::START_TRUST,
        );

        $this->assertSame(
            Relationship::LOSS_SPARED,
            Relationship::lossOutcome($stage, true, false),
        );
    }

    // One step short of that floor still leaves you robbed, so the floor is a
    // threshold the player crosses rather than a number that merely trends.
    public function testOneStepShortOfTheFloorStillRobs(): void
    {
        $stage = Relationship::stage(
            Relationship::SPARE_HOSTILITY_FLOOR + 1,
            Relationship::START_TRUST,
        );

        $this->assertSame(
            Relationship::LOSS_ROBBED,
            Relationship::lossOutcome($stage, true, false),
        );
    }

    public function testOnlyBeingRobbedCostsGoldAndSendsYouHome(): void
    {
        $robbed = Relationship::LOSS_EFFECTS[Relationship::LOSS_ROBBED];
        $spared = Relationship::LOSS_EFFECTS[Relationship::LOSS_SPARED];
        $helped = Relationship::LOSS_EFFECTS[Relationship::LOSS_HELPED];

        $this->assertGreaterThan(0, $robbed['gold_pct']);
        $this->assertTrue($robbed['sent_home']);

        $this->assertSame(0, $spared['gold_pct']);
        $this->assertFalse($spared['sent_home']);

        $this->assertSame(0, $helped['gold_pct']);
        $this->assertFalse($helped['sent_home']);
    }

    // Kindness is legible in the body: each better outcome wakes you stronger.
    public function testEachBetterOutcomeWakesThePlayerWithMoreHealth(): void
    {
        $this->assertGreaterThan(
            Relationship::LOSS_EFFECTS[Relationship::LOSS_ROBBED]['hp_pct'],
            Relationship::LOSS_EFFECTS[Relationship::LOSS_SPARED]['hp_pct'],
        );
        $this->assertGreaterThan(
            Relationship::LOSS_EFFECTS[Relationship::LOSS_SPARED]['hp_pct'],
            Relationship::LOSS_EFFECTS[Relationship::LOSS_HELPED]['hp_pct'],
        );
    }
}
