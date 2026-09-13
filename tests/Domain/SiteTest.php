<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Site;
use Gob\Tests\Support\Fixtures;
use PHPUnit\Framework\TestCase;

// A place in a province, cleared one guarded stage at a time.
final class SiteTest extends TestCase
{
    public function testTheStagesAreReadOutOfTheStoredList(): void
    {
        $site = new Site(Fixtures::siteRow(['stages_json' => '[1,2,5]']));

        $this->assertSame([1, 2, 5], $site->stages());
        $this->assertSame(3, $site->toArray()['total_stages']);
    }

    public function testASiteWithNoStagesHasNone(): void
    {
        $site = new Site(Fixtures::siteRow(['stages_json' => null]));

        $this->assertSame([], $site->stages());
        $this->assertSame(0, $site->toArray()['total_stages']);
    }

    public function testTheNextGuardIsTheOneAtTheCurrentProgress(): void
    {
        $this->assertSame(1, (new Site(Fixtures::siteRow(['progress' => 0])))->nextMonsterId());
        $this->assertSame(2, (new Site(Fixtures::siteRow(['progress' => 1])))->nextMonsterId());
        $this->assertSame(5, (new Site(Fixtures::siteRow(['progress' => 2])))->nextMonsterId());
    }

    public function testAFullyFoughtSiteHasNoNextGuard(): void
    {
        $site = new Site(Fixtures::siteRow(['progress' => 3]));

        $this->assertNull($site->nextMonsterId());
    }

    // A cleared site is done with: offering a guard there would let the player
    // farm a place they have already finished.
    public function testAClearedSiteOffersNoFightHoweverFarItsProgressReads(): void
    {
        $site = new Site(Fixtures::siteRow(['state' => 'cleared', 'progress' => 0]));

        $this->assertNull($site->nextMonsterId());
    }

    public function testAHiddenSiteOffersNoFightEither(): void
    {
        $site = new Site(Fixtures::siteRow(['state' => 'hidden', 'progress' => 0]));

        $this->assertNull($site->nextMonsterId());
    }

    public function testTheNextGuardIsNamedForThePlayerWhenOneIsPassedIn(): void
    {
        $payload = (new Site(Fixtures::siteRow()))->toArray([
            'name'        => 'Goblin Scout',
            'description' => 'A skittish goblin with a rusty dagger.',
        ]);

        $this->assertSame('Goblin Scout', $payload['next_monster']);
        $this->assertSame('A skittish goblin with a rusty dagger.', $payload['next_monster_desc']);
    }

    public function testWithNoGuardPassedInTheSiteNamesNobody(): void
    {
        $payload = (new Site(Fixtures::siteRow()))->toArray();

        $this->assertNull($payload['next_monster']);
        $this->assertNull($payload['next_monster_desc']);
    }

    public function testTheRewardIsReportedAsOneBlock(): void
    {
        $payload = (new Site(Fixtures::siteRow([
            'reward_gold'     => 50,
            'bonus_gold_rate' => 2,
            'bonus_regen'     => 3,
            'reward_item_id'  => 7,
        ])))->toArray();

        $this->assertSame(50, $payload['reward']['gold']);
        $this->assertSame(2, $payload['reward']['gold_rate']);
        $this->assertSame(3, $payload['reward']['regen']);
        $this->assertSame(7, $payload['reward']['item_id']);
    }

    public function testASiteThatRewardsNoItemSaysSoRatherThanNamingItemZero(): void
    {
        $payload = (new Site(Fixtures::siteRow(['reward_item_id' => null])))->toArray();

        $this->assertNull($payload['reward']['item_id']);
    }
}
