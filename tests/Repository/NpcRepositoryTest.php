<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Npc;
use Gob\Domain\Tutor;
use Gob\Repositories;
use Gob\Repository\NpcRepository;
use Gob\Repository\SettlementRepository;
use Gob\Tests\Support\DatabaseTestCase;

// Village residents and the individuals promoted out of spawns the player
// chose not to kill.
final class NpcRepositoryTest extends DatabaseTestCase
{
    private NpcRepository $repo;
    private int $player;
    private int $settlement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(NpcRepository::class);
        $this->player = $this->makePlayer('villager');
        $settlements  = Repositories::get(SettlementRepository::class);
        $settlements->createStarting($this->player);
        $this->settlement = $settlements->homeId($this->player);
    }

    private function province(): int
    {
        $this->db->prepare('INSERT INTO provinces (player_id, name, terrain, level, is_home) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$this->player, 'Red Downs', 'plains', 1, 1]);
        return (int)$this->db->lastInsertId();
    }

    private function goblin(): array
    {
        return ['id' => 1, 'race' => 'goblin'];
    }

    public function testAVillageIsSeededWithItsWholeRoster(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);

        $residents = $this->repo->residents($this->player, $this->settlement);

        $this->assertCount(count(Npc::VILLAGE_ROSTER), $residents);
        $this->assertSame(
            array_column(Npc::VILLAGE_ROSTER, 'name'),
            array_column($residents, 'name'),
        );
    }

    public function testVisitingTwiceDoesNotDoubleThePopulation(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);
        $this->repo->ensureVillage($this->player, $this->settlement);

        $this->assertCount(count(Npc::VILLAGE_ROSTER), $this->repo->residents($this->player, $this->settlement));
    }

    // A tutor's ceiling is a fixed property of the person, not something
    // rerolled each lesson — otherwise a student could reroll their teacher.
    public function testATutorsCeilingIsFixedWhenTheyAreSeeded(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);
        $before = $this->db->query(
            "SELECT teach_ceiling FROM npcs WHERE player_id = {$this->player} AND teaches IS NOT NULL"
        )->fetchColumn();

        $this->repo->ensureVillage($this->player, $this->settlement);
        $after = $this->db->query(
            "SELECT teach_ceiling FROM npcs WHERE player_id = {$this->player} AND teaches IS NOT NULL"
        )->fetchColumn();

        $this->assertSame($before, $after);
    }

    public function testTheScholarTeachesSomeoneElsesTongueOnlyInFragments(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);

        $stmt = $this->db->prepare('SELECT teaches, teach_ceiling FROM npcs WHERE player_id = ? AND teaches IS NOT NULL');
        $stmt->execute([$this->player]);
        $tutor = $stmt->fetch();

        $this->assertSame('goblin', $tutor['teaches']);
        $this->assertGreaterThanOrEqual(Tutor::CEILING_SCHOLAR[0], (int)$tutor['teach_ceiling']);
        $this->assertLessThanOrEqual(Tutor::CEILING_SCHOLAR[1], (int)$tutor['teach_ceiling']);
    }

    // A village created before residents could teach anything backfills the
    // subject on the next visit rather than staying frozen as it was that day.
    public function testAVillageSeededBeforeTutoringExistedIsBackfilled(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);
        $this->db->prepare('UPDATE npcs SET teaches = NULL, teach_ceiling = 0 WHERE player_id = ?')
                 ->execute([$this->player]);

        $this->repo->ensureVillage($this->player, $this->settlement);

        $stmt = $this->db->prepare('SELECT teaches, teach_ceiling FROM npcs WHERE player_id = ? AND teaches IS NOT NULL');
        $stmt->execute([$this->player]);
        $tutor = $stmt->fetch();

        $this->assertSame('goblin', $tutor['teaches']);
        $this->assertGreaterThan(0, (int)$tutor['teach_ceiling']);
    }

    public function testBackfillingDoesNotDisturbAResidentWhoAlreadyTeaches(): void
    {
        $this->repo->ensureVillage($this->player, $this->settlement);
        $this->db->prepare('UPDATE npcs SET teach_ceiling = 99 WHERE player_id = ? AND teaches IS NOT NULL')
                 ->execute([$this->player]);

        $this->repo->ensureVillage($this->player, $this->settlement);

        $this->assertSame(99, (int)$this->db->query(
            "SELECT teach_ceiling FROM npcs WHERE player_id = {$this->player} AND teaches IS NOT NULL"
        )->fetchColumn());
    }

    // A spared, questioned enemy stops being a spawn: it takes a name and a row
    // of its own.
    public function testASparedEnemyBecomesSomeoneWithAName(): void
    {
        $province = $this->province();

        $id = $this->repo->promote($this->player, $this->goblin(), $province, null);

        $this->assertNotNull($id);
        $row = $this->repo->find($id, $this->player);
        $this->assertNotSame('', $row['name']);
        $this->assertSame('goblin', $row['race']);
        $this->assertSame(Npc::SURVIVOR, $row['profession']);
        $this->assertSame(1, (int)$row['monster_id']);
    }

    // A native teaches their own tongue far beyond anything the village has.
    public function testAPromotedIndividualTeachesTheirOwnTongueAtNativeCeilings(): void
    {
        $province = $this->province();

        $id  = $this->repo->promote($this->player, $this->goblin(), $province, null);
        $row = $this->repo->find($id, $this->player);

        $this->assertSame('goblin', $row['teaches']);
        $this->assertGreaterThanOrEqual(Tutor::CEILING_NATIVE[0], (int)$row['teach_ceiling']);
        $this->assertGreaterThan(Tutor::CEILING_SCHOLAR[1], (int)$row['teach_ceiling']);
    }

    // One per race per province, so "someone you know out there" keeps meaning
    // something.
    public function testOnlyOneOfEachPeopleIsPromotedPerProvince(): void
    {
        $province = $this->province();

        $this->assertNotNull($this->repo->promote($this->player, $this->goblin(), $province, null));
        $this->assertNull($this->repo->promote($this->player, $this->goblin(), $province, null));
    }

    public function testADifferentPeopleCanStillBePromotedInTheSameProvince(): void
    {
        $province = $this->province();
        $this->repo->promote($this->player, $this->goblin(), $province, null);

        $this->assertNotNull($this->repo->promote($this->player, ['id' => 2, 'race' => 'wolf'], $province, null));
    }

    public function testNobodyIsPromotedNowhere(): void
    {
        $this->assertNull($this->repo->promote($this->player, $this->goblin(), null, null));
    }

    public function testContactsAreTheIndividualsNotTheVillagers(): void
    {
        $province = $this->province();
        $this->repo->ensureVillage($this->player, $this->settlement);
        $this->repo->promote($this->player, $this->goblin(), $province, null);

        $contacts = $this->repo->contactsIn($this->player, $province);

        $this->assertCount(1, $contacts);
        $this->assertSame('goblin', $contacts[0]['race']);
    }

    public function testThereAreNoContactsNowhere(): void
    {
        $this->assertSame([], $this->repo->contactsIn($this->player, null));
    }

    public function testAnIndividualIsOnlyVisibleToThePlayerWhoSparedThem(): void
    {
        $province = $this->province();
        $id       = $this->repo->promote($this->player, $this->goblin(), $province, null);
        $other    = $this->makePlayer('stranger');

        $this->assertNotNull($this->repo->find($id, $this->player));
        $this->assertNull($this->repo->find($id, $other));
        $this->assertSame([], $this->repo->contactsIn($other, $province));
    }
}
