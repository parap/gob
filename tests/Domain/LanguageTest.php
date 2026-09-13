<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Language;
use PHPUnit\Framework\TestCase;

// A tongue is an ordinary skill named `lang_<race>`, described to the player in
// words rather than numbers.
final class LanguageTest extends TestCase
{
    public function testASkillNameIsBuiltFromTheRace(): void
    {
        $this->assertSame('lang_goblin', Language::skill('goblin'));
    }

    public function testTheRaceIsReadBackOutOfTheSkillName(): void
    {
        $this->assertSame('goblin', Language::raceOf('lang_goblin'));
    }

    public function testOnlyPrefixedSkillsAreTongues(): void
    {
        $this->assertTrue(Language::isLanguage('lang_goblin'));
        $this->assertFalse(Language::isLanguage('sword'));
        $this->assertFalse(Language::isLanguage('attack'));
    }

    public function testTheLabelIsTitleCased(): void
    {
        $this->assertSame('Goblin tongue', Language::label('lang_goblin'));
    }

    // Absent means zero: a character has no row for a tongue nobody taught.
    public function testKnowingNothingIsNotAWord(): void
    {
        $this->assertSame('not a word', Language::fluency(0));
    }

    public function testTheBandsClimbInOrder(): void
    {
        $this->assertSame('not a word', Language::fluency(0));
        $this->assertSame('a few words', Language::fluency(1));
        $this->assertSame('a few words', Language::fluency(9));
        $this->assertSame('broken', Language::fluency(10));
        $this->assertSame('broken', Language::fluency(39));
        $this->assertSame('passable', Language::fluency(40));
        $this->assertSame('passable', Language::fluency(69));
        $this->assertSame('fluent', Language::fluency(70));
        $this->assertSame('fluent', Language::fluency(100));
    }

    // Reading is claimed from literacy up and not before, because that is where
    // it starts being true (§5).
    public function testReadingIsOnlyClaimedFromLiteracyUp(): void
    {
        $this->assertStringNotContainsString('read', Language::sense(Language::LITERACY - 1));
        $this->assertStringContainsString('read', Language::sense(Language::LITERACY));
    }

    public function testTheSentenceIsAuthoredProseNotTheBareTag(): void
    {
        $this->assertSame('You speak it in broken fragments.', Language::sense(20));
        $this->assertStringEndsWith('.', Language::sense(0));
    }

    public function testTheAfterClauseIsAClauseNotASentence(): void
    {
        $this->assertSame('you would get by in it, and read it', Language::senseAfter(50));
        $this->assertStringEndsNotWith('.', Language::senseAfter(50));
    }

    public function testTheBandsAgreeWithEachOtherAtEveryValue(): void
    {
        foreach ([0, 1, 9, 10, 39, 40, 69, 70, 100] as $v) {
            $this->assertNotSame('', Language::fluency($v));
            $this->assertNotSame('', Language::sense($v));
            $this->assertNotSame('', Language::senseAfter($v));
        }
    }

    public function testFragmentsIsTheLowestRungThatIsNotSilence(): void
    {
        $this->assertSame('not a word', Language::fluency(Language::FRAGMENTS - 1));
        $this->assertNotSame('not a word', Language::fluency(Language::FRAGMENTS));
    }
}
