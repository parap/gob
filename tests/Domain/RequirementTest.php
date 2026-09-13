<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Requirement;
use PHPUnit\Framework\TestCase;

// The boolean tree that decides whether the player can perceive a fact — the
// mechanism behind "the same cave reads differently once you understand them".
final class RequirementTest extends TestCase
{
    private const CONTEXT = [
        'skill'     => ['lang_goblin' => 10, 'empathy' => 3],
        'substat'   => ['perception' => 7],
        'trust'     => ['goblin' => 20],
        'hostility' => ['goblin' => 45],
    ];

    // Public knowledge: a fact nobody gated is visible to anybody.
    public function testAnEmptyRequirementIsPublicKnowledge(): void
    {
        $this->assertTrue(Requirement::passes([], self::CONTEXT));
        $this->assertTrue(Requirement::passes(null, self::CONTEXT));
        $this->assertTrue(Requirement::passes('nonsense', self::CONTEXT));
    }

    public function testASkillLeafComparesAgainstItsMinimum(): void
    {
        $this->assertTrue(Requirement::passes(['skill' => 'lang_goblin', 'min' => 10], self::CONTEXT));
        $this->assertTrue(Requirement::passes(['skill' => 'lang_goblin', 'min' => 3], self::CONTEXT));
        $this->assertFalse(Requirement::passes(['skill' => 'lang_goblin', 'min' => 11], self::CONTEXT));
    }

    public function testASkillTheCharacterHasNeverTrainedCountsAsZero(): void
    {
        $this->assertFalse(Requirement::passes(['skill' => 'lang_orc', 'min' => 1], self::CONTEXT));
        $this->assertTrue(Requirement::passes(['skill' => 'lang_orc', 'min' => 0], self::CONTEXT));
    }

    public function testASubstatLeafReadsTheDerivedStats(): void
    {
        $this->assertTrue(Requirement::passes(['substat' => 'perception', 'min' => 7], self::CONTEXT));
        $this->assertFalse(Requirement::passes(['substat' => 'perception', 'min' => 8], self::CONTEXT));
    }

    public function testATrustLeafReadsTheBlendedStanding(): void
    {
        $this->assertTrue(Requirement::passes(['trust' => 'goblin', 'min' => 20], self::CONTEXT));
        $this->assertFalse(Requirement::passes(['trust' => 'goblin', 'min' => 21], self::CONTEXT));
    }

    // Hostility gates from above: a secret stays hidden while they still want
    // you dead, and surfaces once they stop.
    public function testAHostilityLeafGatesFromAbove(): void
    {
        $this->assertTrue(Requirement::passes(['hostility' => 'goblin', 'max' => 45], self::CONTEXT));
        $this->assertTrue(Requirement::passes(['hostility' => 'goblin', 'max' => 50], self::CONTEXT));
        $this->assertFalse(Requirement::passes(['hostility' => 'goblin', 'max' => 44], self::CONTEXT));
    }

    public function testAllDemandsEveryChild(): void
    {
        $tree = ['all' => [
            ['skill' => 'lang_goblin', 'min' => 3],
            ['trust' => 'goblin', 'min' => 20],
        ]];
        $this->assertTrue(Requirement::passes($tree, self::CONTEXT));

        $tree['all'][] = ['skill' => 'empathy', 'min' => 8];
        $this->assertFalse(Requirement::passes($tree, self::CONTEXT));
    }

    public function testAnyAcceptsASingleRoute(): void
    {
        $tree = ['any' => [
            ['skill' => 'empathy', 'min' => 8],
            ['skill' => 'lang_goblin', 'min' => 3],
        ]];

        $this->assertTrue(Requirement::passes($tree, self::CONTEXT));
    }

    public function testAnyRefusesWhenNoRouteIsOpen(): void
    {
        $tree = ['any' => [
            ['skill' => 'empathy', 'min' => 8],
            ['skill' => 'lang_goblin', 'min' => 50],
        ]];

        $this->assertFalse(Requirement::passes($tree, self::CONTEXT));
    }

    // The same secret reachable two ways: understand the words and be trusted,
    // or simply read it off their faces.
    public function testTwoBuildsCanReachTheSameSecret(): void
    {
        $tree = ['any' => [
            ['all' => [['skill' => 'lang_goblin', 'min' => 3], ['trust' => 'goblin', 'min' => 20]]],
            ['skill' => 'empathy', 'min' => 8],
        ]];

        $linguist = ['skill' => ['lang_goblin' => 10], 'trust' => ['goblin' => 20]];
        $empath   = ['skill' => ['empathy' => 9]];
        $neither  = ['skill' => ['lang_goblin' => 10], 'trust' => ['goblin' => 2]];

        $this->assertTrue(Requirement::passes($tree, $linguist));
        $this->assertTrue(Requirement::passes($tree, $empath));
        $this->assertFalse(Requirement::passes($tree, $neither));
    }

    public function testALeafCanBeBoundedFromBothSides(): void
    {
        $leaf = ['skill' => 'lang_goblin', 'min' => 5, 'max' => 15];

        $this->assertTrue(Requirement::passes($leaf, self::CONTEXT));
        $this->assertFalse(Requirement::passes($leaf, ['skill' => ['lang_goblin' => 20]]));
        $this->assertFalse(Requirement::passes($leaf, ['skill' => ['lang_goblin' => 1]]));
    }

    // A leaf naming nothing the engine understands must hide its fact rather
    // than reveal it: an authoring typo should cost a reveal, not leak one.
    public function testALeafTheEngineDoesNotUnderstandRevealsNothing(): void
    {
        $this->assertFalse(Requirement::passes(['reputation' => 'goblin', 'min' => 1], self::CONTEXT));
    }

    public function testTheKeysATreeTestsCanBeListedWithoutEvaluatingIt(): void
    {
        $tree = ['any' => [
            ['all' => [['skill' => 'lang_goblin', 'min' => 3], ['trust' => 'goblin', 'min' => 20]]],
            ['substat' => 'perception', 'min' => 8],
        ]];

        $this->assertSame(
            ['skill:lang_goblin', 'trust:goblin', 'substat:perception'],
            Requirement::keys($tree),
        );
    }

    public function testListingTheKeysOfAnEmptyTreeFindsNothing(): void
    {
        $this->assertSame([], Requirement::keys([]));
        $this->assertSame([], Requirement::keys(null));
    }
}
