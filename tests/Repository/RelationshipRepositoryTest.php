<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Relationship;
use Gob\Repository\RelationshipRepository;
use Gob\Tests\Support\DatabaseTestCase;

// Where a people's opinion is kept. A scope gets a row only once the player has
// done something at it; until then it reads as the scope above, so a first deed
// at a cave starts from what that cave already thought rather than from the
// race default (§2).
final class RelationshipRepositoryTest extends DatabaseTestCase
{
    private RelationshipRepository $repo;
    private int $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = new RelationshipRepository($this->db);
        $this->player = $this->makePlayer('rel');
    }

    private function province(): int
    {
        $this->db->prepare('INSERT INTO provinces (player_id, name, terrain, level, is_home) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$this->player, 'Red Downs', 'plains', 1, 1]);
        return (int)$this->db->lastInsertId();
    }

    private function site(int $provinceId): int
    {
        $this->db->prepare(
            'INSERT INTO province_sites (province_id, player_id, type, name, position, concealment, state)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$provinceId, $this->player, 'dungeon', 'Goblin Cave', 50.00, 10, 'found']);
        return (int)$this->db->lastInsertId();
    }

    private function npc(): int
    {
        $this->db->prepare('INSERT INTO npcs (player_id, race, profession, name, monster_id) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$this->player, 'goblin', 'survivor', 'Yigna', 1]);
        return (int)$this->db->lastInsertId();
    }

    // A player who has never met a goblin is judged by the goblins' opening
    // stance, with no rows written anywhere.
    public function testAStrangerReadsAsTheirPeoplesOpeningStance(): void
    {
        $rel = $this->repo->effective($this->player, 'goblin', null, null);

        $this->assertSame(Relationship::startingHostility('goblin'), $rel->hostility());
        $this->assertSame(Relationship::START_TRUST, $rel->trust());
    }

    public function testAPeopleWithNoEntryUsesTheFallbackStance(): void
    {
        $rel = $this->repo->effective($this->player, 'kobold', null, null);

        $this->assertSame(Relationship::START_HOSTILITY_DEFAULT, $rel->hostility());
    }

    public function testSparingLowersHostilityAndLeavesTrustAlone(): void
    {
        $before = $this->repo->effective($this->player, 'goblin', null, null)->hostility();

        $this->repo->applyDeed($this->player, 'goblin', null, null, -Relationship::SPARE_HOSTILITY_DROP);

        $after = $this->repo->effective($this->player, 'goblin', null, null);
        $this->assertSame($before - Relationship::SPARE_HOSTILITY_DROP, $after->hostility());
        $this->assertSame(Relationship::START_TRUST, $after->trust(), 'sparing buys no trust');
    }

    public function testKillingRaisesHostility(): void
    {
        $before = $this->repo->effective($this->player, 'goblin', null, null)->hostility();

        $this->repo->applyDeed($this->player, 'goblin', null, null, Relationship::KILL_HOSTILITY_RISE);

        $this->assertSame(
            $before + Relationship::KILL_HOSTILITY_RISE,
            $this->repo->effective($this->player, 'goblin', null, null)->hostility(),
        );
    }

    // The floor is how sparing caps at Neutral: no amount of it carries a
    // people past the rung that mercy alone can reach.
    public function testNoAmountOfSparingCarriesAPeoplePastTheFloor(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->repo->applyDeed(
                $this->player, 'goblin', null, null,
                -Relationship::SPARE_HOSTILITY_DROP, 0, Relationship::SPARE_HOSTILITY_FLOOR,
            );
        }

        $rel = $this->repo->effective($this->player, 'goblin', null, null);
        $this->assertSame(Relationship::SPARE_HOSTILITY_FLOOR, $rel->hostility());
        $this->assertSame(Relationship::STAGE_NEUTRAL, $rel->stageIndex());
    }

    public function testAStandingNeverLeavesTheScale(): void
    {
        for ($i = 0; $i < 80; $i++) {
            $this->repo->applyDeed($this->player, 'goblin', null, null, Relationship::KILL_HOSTILITY_RISE);
        }

        $this->assertSame(Relationship::MAX, $this->repo->effective($this->player, 'goblin', null, null)->hostility());
    }

    // Word spreads, but weakly: the deed lands in full where it happened and
    // halves on the way out.
    public function testADeedLandsInFullWhereItHappenedAndHalvesOutward(): void
    {
        $province = $this->province();
        $site     = $this->site($province);
        $start    = Relationship::startingHostility('goblin');

        $this->repo->applyDeed($this->player, 'goblin', $province, $site, -8);

        $atSite     = (int)$this->db->query("SELECT hostility FROM rel_site WHERE player_id = {$this->player}")->fetchColumn();
        $atProvince = (int)$this->db->query("SELECT hostility FROM rel_province WHERE player_id = {$this->player}")->fetchColumn();
        $everywhere = (int)$this->db->query("SELECT hostility FROM rel_generic WHERE player_id = {$this->player}")->fetchColumn();

        $this->assertSame($start - 8, $atSite);
        $this->assertSame($start - 4, $atProvince);
        $this->assertSame($start - 2, $everywhere);
    }

    public function testWhatHappenedAtOneCaveIsNotWhatTheRaceBelievesEverywhere(): void
    {
        $province = $this->province();
        $site     = $this->site($province);

        $this->repo->applyDeed($this->player, 'goblin', $province, $site, -30);

        $here      = $this->repo->effective($this->player, 'goblin', $province, $site)->hostility();
        $elsewhere = $this->repo->effective($this->player, 'goblin', null, null)->hostility();

        $this->assertLessThan($elsewhere, $here);
    }

    // A person you have dealt with directly outweighs everything said about
    // their people — the reason a promoted individual can read Neutral while
    // their race still reads Curious worldwide.
    public function testAPersonYouKnowOutweighsWhatIsSaidAboutTheirPeople(): void
    {
        $npc = $this->npc();

        $this->repo->applyDeed($this->player, 'goblin', null, null, -60, 0, Relationship::MIN, $npc);

        $withThem = $this->repo->effective($this->player, 'goblin', null, null, $npc)->hostility();
        $withKin  = $this->repo->effective($this->player, 'goblin', null, null)->hostility();

        $this->assertLessThan($withKin, $withThem);
    }

    // A first deed at a site must start from what that site already reads as,
    // not snap back to the race default — otherwise progress made everywhere
    // would be silently discarded the first time you act somewhere specific.
    public function testAFirstDeedAtASiteBuildsOnWhatThatSiteAlreadyThought(): void
    {
        $province = $this->province();
        $site     = $this->site($province);
        $start    = Relationship::startingHostility('goblin');

        // Soften the whole race first, with nothing written at the narrow scopes.
        $this->repo->applyDeed($this->player, 'goblin', null, null, -20);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM rel_site WHERE player_id = {$this->player}")->fetchColumn());

        $this->repo->applyDeed($this->player, 'goblin', $province, $site, -6);

        $atSite = (int)$this->db->query("SELECT hostility FROM rel_site WHERE player_id = {$this->player}")->fetchColumn();
        $this->assertSame($start - 20 - 6, $atSite);
    }

    // Statues and risen corpses have no kin to tell.
    public function testNothingIsRememberedAboutAThingWithNoPeople(): void
    {
        $this->repo->applyDeed($this->player, 'none', null, null, -20);

        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM rel_generic WHERE player_id = {$this->player}")->fetchColumn());
    }

    // A deed too faint to register at a distant scope changes nothing there
    // rather than writing a row that says "unchanged". One point at a cave is
    // still felt in that province and no further.
    public function testADeedTooFaintToCarryWritesNoRowWhereItDoesNotReach(): void
    {
        $province = $this->province();
        $site     = $this->site($province);

        $this->repo->applyDeed($this->player, 'goblin', $province, $site, -1);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM rel_site WHERE player_id = {$this->player}")->fetchColumn());
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM rel_province WHERE player_id = {$this->player}")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM rel_generic WHERE player_id = {$this->player}")->fetchColumn());
    }

    // Halving rounds away from zero, so the first step out of a one-point deed
    // is still a full point rather than nothing. That is what keeps a long run
    // of small deeds from being silently swallowed by the decay.
    public function testTheFirstStepOutOfATinyDeedIsNotRoundedAway(): void
    {
        $province = $this->province();
        $start    = Relationship::startingHostility('goblin');

        $this->repo->applyDeed($this->player, 'goblin', $province, null, -1);

        $atProvince = (int)$this->db->query("SELECT hostility FROM rel_province WHERE player_id = {$this->player}")->fetchColumn();
        $everywhere = (int)$this->db->query("SELECT hostility FROM rel_generic WHERE player_id = {$this->player}")->fetchColumn();

        $this->assertSame($start - 1, $atProvince);
        $this->assertSame($start - 1, $everywhere);
    }

    public function testTheWorldListsOnlyPeoplesWithAHistory(): void
    {
        $this->assertSame([], $this->repo->known($this->player));

        $this->repo->applyDeed($this->player, 'goblin', null, null, -6);
        $this->repo->applyDeed($this->player, 'wolf', null, null, 2);

        $known = $this->repo->known($this->player);
        $this->assertSame(['goblin', 'wolf'], array_column($known, 'race'));
        $this->assertArrayHasKey('stage_label', $known[0]);
    }

    public function testOnePlayersDeedsAreInvisibleToAnother(): void
    {
        $other = $this->makePlayer('rel_other');

        $this->repo->applyDeed($this->player, 'goblin', null, null, -40);

        $this->assertSame(
            Relationship::startingHostility('goblin'),
            $this->repo->effective($other, 'goblin', null, null)->hostility(),
        );
    }

    public function testADeedIsRecordedOncePerScopeRatherThanAppended(): void
    {
        $this->repo->applyDeed($this->player, 'goblin', null, null, -6);
        $this->repo->applyDeed($this->player, 'goblin', null, null, -6);

        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM rel_generic WHERE player_id = {$this->player}")->fetchColumn());
        $this->assertSame(
            Relationship::startingHostility('goblin') - 12,
            $this->repo->effective($this->player, 'goblin', null, null)->hostility(),
        );
    }
}
