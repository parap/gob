<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Relationship;
use PHPUnit\Framework\TestCase;

// The arithmetic behind a lost fight: what a loss outcome costs a particular
// character with a particular purse.
final class RelationshipLossTollTest extends TestCase
{
    public function testBeingRobbedLeavesTheHeroOnOneHitPoint(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_ROBBED, 110, 0);

        $this->assertSame(1, $toll['hp']);
    }

    public function testTheOneHitPointFloorHoldsForAFrailHero(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_ROBBED, 4, 0);

        $this->assertSame(1, $toll['hp']);
    }

    public function testBeingSparedWakesTheHeroWeakButAlive(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_SPARED, 110, 0);

        $this->assertSame(11, $toll['hp']);
    }

    public function testBeingHelpedWakesTheHeroPatchedUp(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_HELPED, 110, 0);

        $this->assertSame(38, $toll['hp']);
    }

    // Even the kindest outcome leaves a hero who fought and lost below where
    // they started — being helped is care, not a free heal.
    public function testEvenBeingHelpedCostsTheHeroMostOfTheirHealth(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_HELPED, 110, 0);

        $this->assertLessThan(110, $toll['hp']);
    }

    public function testBeingRobbedTakesAShareOfThePurse(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_ROBBED, 110, 200);

        $this->assertSame(30, $toll['gold']);
    }

    // A pauper cannot be robbed of what they do not carry, and must never end
    // the fight owing gold.
    public function testAnEmptyPurseIsRobbedOfNothing(): void
    {
        $toll = Relationship::lossToll(Relationship::LOSS_ROBBED, 110, 0);

        $this->assertSame(0, $toll['gold']);
    }

    public function testQuarterCostsThePurseNothing(): void
    {
        $spared = Relationship::lossToll(Relationship::LOSS_SPARED, 110, 200);
        $helped = Relationship::lossToll(Relationship::LOSS_HELPED, 110, 200);

        $this->assertSame(0, $spared['gold']);
        $this->assertSame(0, $helped['gold']);
    }

    public function testOnlyTheRobbedAreCarriedHome(): void
    {
        $this->assertTrue(Relationship::lossToll(Relationship::LOSS_ROBBED, 110, 0)['sent_home']);
        $this->assertFalse(Relationship::lossToll(Relationship::LOSS_SPARED, 110, 0)['sent_home']);
        $this->assertFalse(Relationship::lossToll(Relationship::LOSS_HELPED, 110, 0)['sent_home']);
    }
}
