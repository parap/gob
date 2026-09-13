<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Perception;
use PHPUnit\Framework\TestCase;

// How much of a subject one meeting leaves you with. Perception paces
// discovery; it never locks anything away, because facts are authored
// cheapest-first and a dull character simply needs more meetings.
final class PerceptionTest extends TestCase
{
    public function testEveryEncounterTeachesAtLeastOneThing(): void
    {
        $this->assertSame(1, Perception::noticeLimit(0));
    }

    public function testAnUnreadableCharacterStillLearnsSomething(): void
    {
        $this->assertSame(1, Perception::noticeLimit(-5));
    }

    // The pace is stated as the numbers a player actually gets rather than
    // against the constants behind them: comparing noticeLimit() to its own
    // constants passes whatever those constants are changed to, which is no
    // test at all. Retuning the pace should mean editing this list on purpose.
    public function testEachStepOfPerceptionAddsOneMoreNoticedThing(): void
    {
        $this->assertSame(1, Perception::noticeLimit(5));
        $this->assertSame(2, Perception::noticeLimit(6));
        $this->assertSame(2, Perception::noticeLimit(11));
        $this->assertSame(3, Perception::noticeLimit(12));
        $this->assertSame(4, Perception::noticeLimit(18));
    }

    // Even a sharp eye does not exhaust a creature at a glance — reading one
    // stays something that takes repeated meetings.
    public function testNoEyeIsSharpEnoughToExhaustASubjectAtOnce(): void
    {
        $this->assertSame(4, Perception::noticeLimit(1000));
        $this->assertSame(4, Perception::noticeLimit(24));
        $this->assertSame(Perception::NOTICE_MAX, Perception::noticeLimit(1000));
    }

    public function testTheLimitNeverFalls(): void
    {
        $previous = 0;
        for ($p = 0; $p <= 60; $p++) {
            $limit = Perception::noticeLimit($p);
            $this->assertGreaterThanOrEqual($previous, $limit);
            $previous = $limit;
        }
    }
}
