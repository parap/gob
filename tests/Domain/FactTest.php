<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Fact;
use PHPUnit\Framework\TestCase;

// One knowable thing about a subject, gated by a requirement and filed under
// the channel — the skill — that opens it.
final class FactTest extends TestCase
{
    private function fact(array $overrides = []): Fact
    {
        return new Fact($overrides + [
            'id'               => 1,
            'channel'          => 'observation',
            'content'          => 'Its dagger is notched from chopping roots, not armour.',
            'requirement_json' => null,
        ]);
    }

    public function testAFactCarriesItsChannelLabel(): void
    {
        $this->assertSame('What you see', $this->fact()->toArray()['label']);
    }

    public function testAnUnknownChannelFallsBackToItsOwnName(): void
    {
        $this->assertSame('rumour', $this->fact(['channel' => 'rumour'])->toArray()['label']);
    }

    public function testARequirementStoredAsJsonIsDecoded(): void
    {
        $f = $this->fact(['requirement_json' => '{"skill":"lang_goblin","min":5}']);

        $this->assertSame(['skill' => 'lang_goblin', 'min' => 5], $f->requirement());
    }

    public function testARequirementAlreadyDecodedIsLeftAlone(): void
    {
        $f = $this->fact(['requirement_json' => ['skill' => 'lang_goblin', 'min' => 5]]);

        $this->assertSame(['skill' => 'lang_goblin', 'min' => 5], $f->requirement());
    }

    public function testAnUngatedFactIsVisibleToAnybody(): void
    {
        $this->assertTrue($this->fact()->passes([]));
    }

    public function testMalformedRequirementJsonLeavesTheFactPublicRatherThanLost(): void
    {
        $f = $this->fact(['requirement_json' => 'not json at all']);

        $this->assertSame([], $f->requirement());
        $this->assertTrue($f->passes([]));
    }

    public function testAGatedFactConsultsTheContext(): void
    {
        $f = $this->fact(['requirement_json' => '{"skill":"lang_goblin","min":5}']);

        $this->assertFalse($f->passes(['skill' => ['lang_goblin' => 4]]));
        $this->assertTrue($f->passes(['skill' => ['lang_goblin' => 5]]));
    }

    // The encounter view reads the same way every time, so the channels come
    // out in the canonical order however the rows arrived.
    public function testGroupingFollowsTheCanonicalChannelOrder(): void
    {
        $grouped = Fact::group([
            ['channel' => 'speech', 'content' => 'A'],
            ['channel' => 'observation', 'content' => 'B'],
            ['channel' => 'lore', 'content' => 'C'],
        ]);

        $this->assertSame(['observation', 'speech', 'lore'], array_column($grouped, 'channel'));
    }

    public function testGroupingKeepsSeveralFactsUnderOneHeading(): void
    {
        $grouped = Fact::group([
            ['channel' => 'observation', 'content' => 'A'],
            ['channel' => 'observation', 'content' => 'B'],
        ]);

        $this->assertCount(1, $grouped);
        $this->assertSame(['A', 'B'], $grouped[0]['facts']);
        $this->assertSame('What you see', $grouped[0]['label']);
    }

    // An empty heading would advertise that something is there to be found,
    // which is exactly the push the design refuses (§1).
    public function testAChannelWithNothingToSayIsNotShownAtAll(): void
    {
        $grouped = Fact::group([['channel' => 'observation', 'content' => 'A']]);

        $this->assertCount(1, $grouped);
        $this->assertSame([], Fact::group([]));
    }

    // Recall is not a channel a fact can be authored in: it is the single
    // heading a browsing list gets, because remembering a creature is not
    // standing in front of one.
    public function testRecallIsNotSomethingAFactCanBeAuthoredIn(): void
    {
        $this->assertArrayNotHasKey(Fact::RECALL_CHANNEL, Fact::CHANNELS);
        $this->assertNotSame('', Fact::RECALL_LABEL);
    }

    public function testEveryChannelHasAHeading(): void
    {
        foreach (Fact::CHANNELS as $channel => $label) {
            $this->assertIsString($channel);
            $this->assertNotSame('', $label);
        }
    }
}
