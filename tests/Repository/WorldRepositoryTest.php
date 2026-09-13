<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Repository\WorldRepository;
use Gob\Tests\Support\DatabaseTestCase;

// Provinces, the sites scattered through them, and the sweep that uncovers
// them. Everything here is per player: two heroes never share a map.
final class WorldRepositoryTest extends DatabaseTestCase
{
    private WorldRepository $repo;
    private int $player;
    private int $charId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(WorldRepository::class);
        $this->player = $this->makePlayer('explorer');
        $this->charId = Repositories::get(CharacterRepository::class)->ensure($this->player, 'Gareth');
    }

    private function site(int $provinceId, array $overrides = []): int
    {
        $this->repo->insertSite($overrides + [
            'province_id' => $provinceId,
            'player_id'   => $this->player,
            'type'        => 'dungeon',
            'name'        => 'Goblin Cave',
            'position'    => 25.00,
            'concealment' => 10,
            'stages_json' => '[1,2,5]',
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function testTheFirstProvinceIsHome(): void
    {
        $id = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);

        $this->assertSame($id, $this->repo->homeProvinceId($this->player));
        $this->assertSame('Red Downs', $this->repo->provinceBrief($id)['name']);
    }

    public function testAPlayerWithNoProvincesHasNoHome(): void
    {
        $this->assertNull($this->repo->homeProvinceId($this->makePlayer('nomad')));
    }

    // Home is the province flagged as home, not merely the one the hero is
    // standing in — which is the whole point of being carried back to it.
    public function testHomeIsNotSimplyWhereverTheHeroHappensToBe(): void
    {
        $home = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $away = $this->repo->createProvince($this->player, 'Far Reach', 'forest', 2, false);
        $this->repo->setCurrentProvince($this->charId, $away);

        $this->assertSame($away, $this->repo->currentProvinceId($this->charId));
        $this->assertSame($home, $this->repo->homeProvinceId($this->player));
    }

    public function testAHeroWhoHasNeverTravelledIsNowhereInParticular(): void
    {
        $this->assertNull($this->repo->currentProvinceId($this->charId));
    }

    public function testProvincesAreListedPerPlayer(): void
    {
        $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $other = $this->makePlayer('rival_explorer');
        $this->repo->createProvince($other, 'Grey Fen', 'swamp', 1, true);

        $this->assertCount(1, $this->repo->provincesForPlayer($this->player));
        $this->assertCount(1, $this->repo->provincesForPlayer($other));
    }

    public function testAProvinceIsOnlyFoundByItsOwner(): void
    {
        $id    = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $other = $this->makePlayer('trespasser');

        $this->assertNotNull($this->repo->findProvince($id, $this->player));
        $this->assertNull($this->repo->findProvince($id, $other));
    }

    public function testExplorationProgressIsRecorded(): void
    {
        $id = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);

        $this->repo->updateExplored($id, 42.5);

        $this->assertSame(42.5, (float)$this->repo->findProvince($id, $this->player)['explored_pct']);
    }

    // A sweep uncovers what it has actually reached and nothing beyond it.
    public function testASweepOnlyReachesSitesItHasWalkedPast(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $near     = $this->site($province, ['position' => 10.00, 'name' => 'Near Cave']);
        $far      = $this->site($province, ['position' => 80.00, 'name' => 'Far Cave']);

        $swept = array_column($this->repo->hiddenSweptSites($province, 20.0), 'id');

        $this->assertContains($near, array_map('intval', $swept));
        $this->assertNotContains($far, array_map('intval', $swept));
    }

    public function testASiteAlreadyFoundIsNotFoundAgainBySweeping(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province, ['position' => 10.00]);
        $this->repo->setSiteState($id, 'found');

        $this->assertSame([], $this->repo->hiddenSweptSites($province, 100.0));
    }

    public function testANewSiteStartsHiddenAndUndiscovered(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province);

        $row = $this->repo->findSite($id, $this->player);
        $this->assertSame('hidden', $row['state']);
        $this->assertNull($row['found_at']);
        $this->assertSame([], $this->repo->visibleSites($this->player));
    }

    // The list orders by discovery, so a fresh find lands on top rather than
    // wherever world generation happened to put it.
    public function testUncoveringASiteStampsWhenItWasFound(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province);

        $this->repo->setSiteState($id, 'found');

        $this->assertNotNull($this->repo->findSite($id, $this->player)['found_at']);
        $this->assertCount(1, $this->repo->visibleSites($this->player));
    }

    public function testReFindingASiteKeepsTheOriginalDiscoveryStamp(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province);
        $this->repo->setSiteState($id, 'found');
        $first = $this->repo->findSite($id, $this->player)['found_at'];

        $this->repo->setSiteState($id, 'cleared');
        $this->repo->setSiteState($id, 'found');

        $this->assertSame($first, $this->repo->findSite($id, $this->player)['found_at']);
    }

    public function testProgressThroughASiteIsRecorded(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province);

        $this->repo->setSiteProgress($id, 2);
        $this->assertSame(2, (int)$this->repo->findSite($id, $this->player)['progress']);

        $this->repo->setSiteProgressState($id, 3, 'cleared');
        $row = $this->repo->findSite($id, $this->player);
        $this->assertSame(3, (int)$row['progress']);
        $this->assertSame('cleared', $row['state']);
    }

    public function testASiteIsOnlyReachableByItsOwner(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province);
        $other    = $this->makePlayer('site_trespasser');

        $this->assertNotNull($this->repo->findSite($id, $this->player));
        $this->assertNull($this->repo->findSite($id, $other));
    }

    public function testARaidComesFromAStillHiddenDungeon(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $this->site($province, ['type' => 'dungeon']);

        $this->assertNotNull($this->repo->randomHiddenDungeon($this->player));
    }

    public function testNoRaidComesFromAMapWithNothingLeftHidden(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $id       = $this->site($province, ['type' => 'dungeon']);
        $this->repo->setSiteState($id, 'found');

        $this->assertNull($this->repo->randomHiddenDungeon($this->player));
    }

    // A goblin knows where goblins are, not where the ogres sleep.
    public function testAPrisonerGivesUpAPlaceTheirOwnKinHold(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $goblins  = $this->site($province, ['name' => 'Goblin Cave', 'stages_json' => '[1]']);
        $this->site($province, ['name' => 'Ogre Den', 'stages_json' => '[4]']);

        for ($i = 0; $i < 15; $i++) {
            $this->assertSame(
                $goblins,
                (int)$this->repo->randomHiddenSite($this->player, $province, 'goblin')['id'],
            );
        }
    }

    // An interrogation in a thoroughly-searched corner still has something to
    // offer rather than silently yielding nothing.
    public function testAPrisonerWithNoKinNearbyStillNamesSomewhere(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $ogres    = $this->site($province, ['name' => 'Ogre Den', 'stages_json' => '[4]']);

        $this->assertSame(
            $ogres,
            (int)$this->repo->randomHiddenSite($this->player, $province, 'goblin')['id'],
        );
    }

    public function testARoadIsNeverGivenUpAsASecretPlace(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $this->site($province, ['type' => 'road', 'name' => 'Old Road', 'stages_json' => null]);

        $this->assertNull($this->repo->randomHiddenSite($this->player, $province, 'goblin'));
    }

    public function testARaidCanOnlyRetakeABoonThatStillGrantsSomething(): void
    {
        $province = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $barren   = $this->site($province, ['type' => 'boon', 'name' => 'Spent Well']);
        $this->repo->setSiteState($barren, 'cleared');

        $this->assertNull($this->repo->randomClearedBoon($this->player));

        $rich = $this->site($province, ['type' => 'boon', 'name' => 'Gold Seam', 'bonus_gold_rate' => 5]);
        $this->repo->setSiteState($rich, 'cleared');

        $this->assertSame($rich, (int)$this->repo->randomClearedBoon($this->player)['id']);
    }

    // A road joins two provinces once, whichever end it is described from.
    public function testARoadBetweenTwoProvincesIsRecordedOnlyOnce(): void
    {
        $a = $this->repo->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $b = $this->repo->createProvince($this->player, 'Far Reach', 'forest', 2, false);

        $this->repo->link($this->player, $a, $b);
        $this->repo->link($this->player, $b, $a);

        $this->assertSame(1, (int)$this->db->query(
            "SELECT COUNT(*) FROM province_links WHERE player_id = {$this->player}"
        )->fetchColumn());
    }
}
