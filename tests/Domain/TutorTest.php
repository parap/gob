<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Tutor;
use PHPUnit\Framework\TestCase;

// Teaching has no hard level caps: every tutor has a ceiling, and a session
// closes a fraction of the gap to it. So almost anyone helps at first, and real
// fluency eventually demands a better tutor than the village has (§5).
final class TutorTest extends TestCase
{
    public function testASessionClosesAFractionOfTheGap(): void
    {
        $this->assertSame(7, Tutor::gain(0, 20));    // floor(20 * 0.35)
        $this->assertSame(4, Tutor::gain(8, 20));    // floor(12 * 0.35)
    }

    // The more you know, the less any one tutor adds — the reason fluency
    // cannot be ground out of the village scholar.
    public function testEachSessionWithTheSameTutorIsWorthLess(): void
    {
        $ceiling = 35;
        $current = 0;
        $previous = PHP_INT_MAX;
        for ($i = 0; $i < 6; $i++) {
            $gain = Tutor::gain($current, $ceiling);
            $this->assertLessThanOrEqual($previous, $gain);
            $previous = $gain;
            $current += $gain;
        }
        $this->assertLessThan($ceiling, $current, 'the ceiling is approached, never reached');
    }

    // "You already speak it better than I do."
    public function testATutorYouHaveOvertakenTeachesNothing(): void
    {
        $this->assertSame(0, Tutor::gain(35, 35));
        $this->assertSame(0, Tutor::gain(60, 35));
    }

    // A session that would round to nothing still teaches one point, so the
    // last crumbs are slow rather than impossible.
    public function testTheLastCrumbsAreStillWorthOnePoint(): void
    {
        $this->assertSame(1, Tutor::gain(34, 35));
        $this->assertSame(1, Tutor::gain(33, 35));
    }

    public function testTuitionIsPricedPerPointGained(): void
    {
        $this->assertSame(7 * Tutor::GOLD_PER_POINT, Tutor::price(7));
        $this->assertSame(0, Tutor::price(0));
    }

    public function testTimeIsAlsoSpentPerPointGained(): void
    {
        $this->assertSame(7 * Tutor::SECONDS_PER_POINT, Tutor::seconds(7));
        $this->assertSame(0, Tutor::seconds(0));
    }

    public function testGoldAndTimeAreSeparateRates(): void
    {
        $this->assertNotSame(Tutor::GOLD_PER_POINT, Tutor::SECONDS_PER_POINT);
    }

    public function testAVillageScholarRollsWithinTheirModestRange(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $c = Tutor::rollCeiling(false);
            $this->assertGreaterThanOrEqual(Tutor::CEILING_SCHOLAR[0], $c);
            $this->assertLessThanOrEqual(Tutor::CEILING_SCHOLAR[1], $c);
        }
    }

    public function testANativeSpeakerRollsFarHigher(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $c = Tutor::rollCeiling(true);
            $this->assertGreaterThanOrEqual(Tutor::CEILING_NATIVE[0], $c);
            $this->assertLessThanOrEqual(Tutor::CEILING_NATIVE[1], $c);
        }
    }

    // The gate the whole mercy loop pays off into: no village scholar can take
    // a student as far as the worst native speaker.
    public function testNoScholarCanTeachWhatTheWorstNativeSpeakerCan(): void
    {
        $this->assertLessThan(Tutor::CEILING_NATIVE[0], Tutor::CEILING_SCHOLAR[1]);
    }
}
