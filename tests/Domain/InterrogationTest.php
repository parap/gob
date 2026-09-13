<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Interrogation;
use PHPUnit\Framework\TestCase;

// Squeezing a spared prisoner. The same line resolves further as the tongue
// grows — the "GRAAAH becomes words" reveal done as text rather than as a
// special case (§4).
final class InterrogationTest extends TestCase
{
    public function testUnderstandingNothingIsNoComprehensionAtAll(): void
    {
        $this->assertSame(0.0, Interrogation::comprehension(0));
    }

    public function testComprehensionClimbsWithTheTongue(): void
    {
        $this->assertEqualsWithDelta(0.5, Interrogation::comprehension(25), 0.001);
        $this->assertSame(1.0, Interrogation::comprehension(Interrogation::FULL_UNDERSTANDING));
    }

    // Past full understanding there is nothing more to understand; the value
    // must not run over 1.0 and start corrupting the word-survival maths.
    public function testComprehensionNeverRunsPastEverything(): void
    {
        $this->assertSame(1.0, Interrogation::comprehension(100));
        $this->assertSame(1.0, Interrogation::comprehension(1000));
    }

    public function testAFluentListenerHearsTheWholeSentence(): void
    {
        $line = 'We took the grain because the winter took our fields.';

        $this->assertSame($line, Interrogation::heard($line, Interrogation::FULL_UNDERSTANDING));
    }

    public function testSomeoneWithoutAWordHearsOnlyGaps(): void
    {
        $line = 'We took the grain because the winter took our fields.';

        $this->assertSame('…', Interrogation::heard($line, 0));
    }

    // At a few words most of it is gaps, but what lands is never the whole
    // sentence and never nothing at all across repeated hearings.
    public function testABrokenListenerHearsFragmentsRatherThanAllOrNothing(): void
    {
        $line   = 'The warlord keeps the best of it. We eat what is left of what we steal.';
        $heard  = [];
        for ($i = 0; $i < 60; $i++) {
            $heard[] = Interrogation::heard($line, 12);
        }

        $this->assertNotContains($line, $heard, 'a broken listener never gets the whole line');
        $this->assertGreaterThan(1, count(array_unique($heard)), 'the rendering varies between hearings');

        $withWords = array_filter($heard, fn(string $h) => $h !== '…');
        $this->assertNotEmpty($withWords, 'something lands at least sometimes');
    }

    // Longer words are the content words; biasing them to survive is what makes
    // a fragment read as "…grain… …winter…" rather than as "the of we".
    public function testTheWordsThatSurviveAreTheOnesWorthCatching(): void
    {
        $line  = 'We took the grain because the winter took our fields.';
        $long  = 0;
        $short = 0;
        for ($i = 0; $i < 300; $i++) {
            $heard = Interrogation::heard($line, 20);
            $long  += substr_count($heard, 'grain') + substr_count($heard, 'winter') + substr_count($heard, 'fields');
            $short += substr_count($heard, ' the ') + substr_count($heard, 'We ') + substr_count($heard, 'our ');
        }

        $this->assertGreaterThan($short, $long);
    }

    public function testRunsOfGapsCollapseSoItReadsAsSpeech(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $heard = Interrogation::heard('We took the grain because the winter took our fields.', 8);
            $this->assertStringNotContainsString('… …', $heard);
        }
    }

    public function testAPrisonerSpeaksALineFromTheirOwnPeoplesPool(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertContains(Interrogation::line('goblin'), Interrogation::LINES['goblin']);
        }
    }

    // Only goblins have lines authored; anyone else falls back rather than
    // erroring, so adding a race cannot crash an interrogation.
    public function testAPeopleWithNoLinesFallsBackInsteadOfFailing(): void
    {
        $this->assertContains(Interrogation::line('orc'), Interrogation::LINES_FALLBACK);
    }

    public function testEveryGoblinLineCracksTheMindlessRaiderStory(): void
    {
        foreach (Interrogation::LINES['goblin'] as $line) {
            $this->assertNotSame('', trim($line));
            $this->assertStringEndsWith('.', $line);
        }
    }

    public function testTheChanceOfIntelClimbsWithTheTongue(): void
    {
        $this->assertSame(Interrogation::INTEL_BASE_PCT, Interrogation::intelChance(0));
        $this->assertGreaterThan(Interrogation::intelChance(0), Interrogation::intelChance(20));
        $this->assertGreaterThan(Interrogation::intelChance(20), Interrogation::intelChance(60));
    }

    public function testTheChanceOfAStashClimbsMoreSlowlyThanIntel(): void
    {
        $this->assertSame(Interrogation::STASH_BASE_PCT, Interrogation::stashChance(0));
        $this->assertLessThan(Interrogation::intelChance(40), Interrogation::stashChance(40));
    }

    // Intel is the headline value, so it must be likelier than the coin.
    public function testIntelIsAlwaysLikelierThanCoin(): void
    {
        for ($lang = 0; $lang <= 100; $lang += 10) {
            $this->assertGreaterThan(Interrogation::stashChance($lang), Interrogation::intelChance($lang));
        }
    }

    public function testAStashIsWorthSomethingWithinItsBand(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $gold = Interrogation::stashGold();
            $this->assertGreaterThanOrEqual(Interrogation::STASH_GOLD[0], $gold);
            $this->assertLessThanOrEqual(Interrogation::STASH_GOLD[1], $gold);
        }
    }
}
