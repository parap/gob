<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Relationship;
use PHPUnit\Framework\TestCase;

// How a people feels about the player, on two independent axes blended over
// nested scopes so direct experience outweighs rumour (§2).
final class RelationshipTest extends TestCase
{
    public function testANamedPeopleStartsWhereTheirEntrySaysTheyDo(): void
    {
        $this->assertSame(80, Relationship::startingHostility('goblin'));
        $this->assertSame(55, Relationship::startingHostility('wolf'));
    }

    // Pinned as a number, not against the constant it reads: asserting
    // startingHostility() equals START_HOSTILITY_DEFAULT passes for any value
    // the constant is given, and so guards nothing.
    public function testAPeopleNobodyWroteAnEntryForFallsBackToTheDefault(): void
    {
        $this->assertSame(75, Relationship::startingHostility('kobold'));
        $this->assertSame(
            Relationship::START_HOSTILITY_DEFAULT,
            Relationship::startingHostility('kobold'),
        );
    }

    // An unlisted people is treated as one of the hostile ones, not as a
    // neutral stranger: meeting something nobody wrote an entry for should not
    // be friendlier than meeting a goblin.
    public function testAnUnlistedPeopleIsAssumedHostileRatherThanNeutral(): void
    {
        $this->assertSame(
            Relationship::STAGE_MONSTER,
            Relationship::stage(Relationship::startingHostility('kobold'), Relationship::START_TRUST),
        );
    }

    // Beasts fight from hunger, not hatred, and it shows in where they start.
    public function testBeastsBeginLessHostileThanTheWorstPeoples(): void
    {
        $this->assertLessThan(
            Relationship::START_HOSTILITY['orc'],
            Relationship::START_HOSTILITY['wolf'],
        );
    }

    public function testNobodyStartsOutsideTheScale(): void
    {
        foreach (Relationship::START_HOSTILITY as $race => $value) {
            $this->assertGreaterThanOrEqual(Relationship::MIN, $value, $race);
            $this->assertLessThanOrEqual(Relationship::MAX, $value, $race);
        }
        $this->assertSame(0, Relationship::START_TRUST, 'trust is earned, never given');
    }

    // Mercy is keyed off what a thing *is*, not who its people are: there is
    // nobody home in a risen corpse or a war statue.
    public function testThereIsNobodyHomeInTheUnsparableNatures(): void
    {
        $this->assertFalse(Relationship::isSparable('undead'));
        $this->assertFalse(Relationship::isSparable('construct'));
        $this->assertFalse(Relationship::isSparable('plant'));
    }

    public function testAnythingThatThinksCanBeSpared(): void
    {
        $this->assertTrue(Relationship::isSparable('mortal'));
        $this->assertTrue(Relationship::isSparable('beast'));
        $this->assertTrue(Relationship::isSparable('magical'));
    }

    // A statue has no kin to tell, so no relationship rows are kept for it.
    public function testAThingWithNoPeopleHoldsNoOpinion(): void
    {
        $this->assertFalse(Relationship::tracksOpinion('none'));
        $this->assertFalse(Relationship::tracksOpinion('unknown'));
        $this->assertFalse(Relationship::tracksOpinion(''));
    }

    public function testAPeopleHoldsAnOpinion(): void
    {
        $this->assertTrue(Relationship::tracksOpinion('goblin'));
    }

    public function testTheScaleHasBothEnds(): void
    {
        $this->assertSame(Relationship::MIN, Relationship::clamp(-40));
        $this->assertSame(Relationship::MAX, Relationship::clamp(140));
        $this->assertSame(50, Relationship::clamp(50));
    }

    // A total stranger is simply their race's reputation: every narrower scope
    // inherits the next-broader one rather than counting as zero.
    public function testAStrangerIsJudgedEntirelyByTheirPeoplesReputation(): void
    {
        $this->assertSame(80, Relationship::blend(['generic' => 80]));
    }

    // Direct experience outweighs hearsay: that is what the weights are for.
    public function testWhatHappenedHereOutweighsWhatIsSaidEverywhere(): void
    {
        $blend = Relationship::blend(['generic' => 80, 'site' => 0]);

        $this->assertLessThan(40, $blend, 'the narrow scopes carry most of the weight');
    }

    public function testAPersonYouKnowOutweighsTheirWholePeople(): void
    {
        $blend = Relationship::blend(['generic' => 100, 'npc' => 0]);

        $this->assertLessThan(50, $blend);
    }

    public function testEachNarrowerScopeCarriesMoreWeightThanTheLast(): void
    {
        $previous = 0;
        foreach (Relationship::SCOPES as $scope) {
            $this->assertGreaterThan($previous, Relationship::WEIGHTS[$scope], $scope);
            $previous = Relationship::WEIGHTS[$scope];
        }
    }

    public function testAgreementAtEveryScopeBlendsToThatValue(): void
    {
        $this->assertSame(60, Relationship::blend([
            'generic' => 60, 'province' => 60, 'site' => 60, 'npc' => 60,
        ]));
    }

    public function testAnOpinionNobodyHoldsAnywhereBlendsToNothing(): void
    {
        $this->assertSame(0, Relationship::blend([]));
    }

    public function testHostilityGatesTheBottomOfTheLadder(): void
    {
        $this->assertSame(Relationship::STAGE_MONSTER, Relationship::stage(80, 0));
        $this->assertSame(Relationship::STAGE_MONSTER, Relationship::stage(Relationship::HOSTILE_MONSTER, 0));
        $this->assertSame(Relationship::STAGE_CURIOUS, Relationship::stage(Relationship::HOSTILE_MONSTER - 1, 0));
        $this->assertSame(Relationship::STAGE_NEUTRAL, Relationship::stage(Relationship::HOSTILE_CURIOUS - 1, 0));
    }

    // Trust gates the top, and it is worth nothing while they still want you
    // dead — a trusted enemy is still an enemy.
    public function testTrustBuysNothingWhileTheyStillWantYouDead(): void
    {
        $this->assertSame(Relationship::STAGE_MONSTER, Relationship::stage(90, 100));
    }

    public function testTrustClimbsTheTopOfTheLadderOnceHostilityIsDown(): void
    {
        $this->assertSame(Relationship::STAGE_NEUTRAL, Relationship::stage(10, 0));
        $this->assertSame(Relationship::STAGE_FRIENDLY, Relationship::stage(10, 30));
        $this->assertSame(Relationship::STAGE_ALLY, Relationship::stage(10, 80));
    }

    public function testEveryRungHasALabel(): void
    {
        $this->assertCount(5, Relationship::STAGE_LABELS);
        for ($h = 0; $h <= 100; $h += 5) {
            for ($t = 0; $t <= 100; $t += 25) {
                $stage = Relationship::stage($h, $t);
                $this->assertArrayHasKey($stage, Relationship::STAGE_LABELS);
            }
        }
    }

    // Sparing teaches a people you are not an exterminator and nothing more:
    // it carries Hostility to exactly Neutral, and the rest of the ladder is
    // bought with Trust, which only helping them raises.
    public function testSparingStopsExactlyAtNeutral(): void
    {
        $this->assertSame(
            Relationship::STAGE_NEUTRAL,
            Relationship::stage(Relationship::SPARE_HOSTILITY_FLOOR, Relationship::START_TRUST),
        );
        $this->assertSame(
            Relationship::STAGE_CURIOUS,
            Relationship::stage(Relationship::SPARE_HOSTILITY_FLOOR + 1, Relationship::START_TRUST),
        );
    }

    public function testSparingMovesAPeopleFurtherThanKillingDoes(): void
    {
        $this->assertGreaterThan(
            Relationship::KILL_HOSTILITY_RISE,
            Relationship::SPARE_HOSTILITY_DROP,
        );
    }

    public function testSomeEnemiesWouldRatherDieThanBeTakenAlive(): void
    {
        $fired = 0;
        for ($i = 0; $i < 4000; $i++) {
            if (Relationship::rollFanatic()) {
                $fired++;
            }
        }

        $this->assertEqualsWithDelta(Relationship::FANATIC_PCT, $fired / 40, 4.0);
    }

    public function testSomeWinnersTakeNoPrisoners(): void
    {
        $fired = 0;
        for ($i = 0; $i < 4000; $i++) {
            if (Relationship::rollNoQuarter()) {
                $fired++;
            }
        }

        $this->assertEqualsWithDelta(Relationship::NO_QUARTER_PCT, $fired / 40, 4.0);
    }

    public function testTheReportedStandingNamesItsRung(): void
    {
        $view = (new Relationship('goblin', 80, 0))->toArray();

        $this->assertSame('goblin', $view['race']);
        $this->assertSame(80, $view['hostility']);
        $this->assertSame(0, $view['trust']);
        $this->assertSame(Relationship::STAGE_MONSTER, $view['stage']);
        $this->assertSame('Monster', $view['stage_label']);
    }

    public function testTheAxesAreReportedSeparately(): void
    {
        $r = new Relationship('goblin', 30, 70);

        $this->assertSame(30, $r->hostility());
        $this->assertSame(70, $r->trust());
        $this->assertSame(Relationship::STAGE_ALLY, $r->stageIndex());
    }
}
