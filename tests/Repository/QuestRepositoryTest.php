<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Quest;
use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Repository\ItemRepository;
use Gob\Repository\QuestRepository;
use Gob\Tests\Support\DatabaseTestCase;

// Quest instances: who offers what, what counts as progress, and what proof is
// demanded at the counter.
final class QuestRepositoryTest extends DatabaseTestCase
{
    private QuestRepository $repo;
    private int $player;
    private int $charId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(QuestRepository::class);
        $this->player = $this->makePlayer('quester');
        $this->charId = Repositories::get(CharacterRepository::class)->ensure($this->player, 'Gareth');
    }

    private function elder(): array
    {
        $this->db->prepare('INSERT INTO npcs (player_id, race, profession, name) VALUES (?, ?, ?, ?)')
                 ->execute([$this->player, 'human', 'elder', 'Elder Maroun']);
        $id = (int)$this->db->lastInsertId();
        return ['id' => $id, 'profession' => 'elder'];
    }

    private function accept(): int
    {
        $elder = $this->elder();
        return $this->repo->create($this->player, $elder['id'], $this->repo->offerFor($this->player, $elder));
    }

    public function testTheElderOffersTheQuestWrittenForTheirProfession(): void
    {
        $offer = $this->repo->offerFor($this->player, $this->elder());

        $this->assertSame('clear_goblins', $offer['key']);
        $this->assertSame('goblin', $offer['target_race']);
    }

    public function testSomeoneWithNoQuestWrittenForThemOffersNothing(): void
    {
        $this->assertNull($this->repo->offerFor($this->player, ['id' => 1, 'profession' => 'healer']));
    }

    // Once taken, the offer is gone — a giver does not hand out the same errand
    // twice, even after it has been turned in.
    public function testAQuestAlreadyTakenIsNotOfferedAgain(): void
    {
        $elder = $this->elder();
        $id    = $this->repo->create($this->player, $elder['id'], $this->repo->offerFor($this->player, $elder));

        $this->assertNull($this->repo->offerFor($this->player, $elder));

        $this->repo->markTurnedIn($id);
        $this->assertNull($this->repo->offerFor($this->player, $elder));
    }

    public function testAnAcceptedQuestStartsActiveAtNoProgress(): void
    {
        $q = $this->repo->find($this->accept(), $this->player);

        $this->assertSame('active', $q['state']);
        $this->assertSame(0, (int)$q['progress']);
        $this->assertSame(5, (int)$q['target_count']);
    }

    public function testKillingTheRightRaceAdvancesTheHunt(): void
    {
        $id = $this->accept();

        $this->repo->advanceKills($this->player, 'goblin');
        $this->repo->advanceKills($this->player, 'goblin');

        $this->assertSame(2, (int)$this->repo->find($id, $this->player)['progress']);
    }

    public function testKillingSomethingElseAdvancesNothing(): void
    {
        $id = $this->accept();

        $this->repo->advanceKills($this->player, 'wolf');
        $this->repo->advanceKills($this->player, '');

        $this->assertSame(0, (int)$this->repo->find($id, $this->player)['progress']);
    }

    // The quest flips to done on the kill that meets the count, not one before
    // and not one after.
    public function testTheHuntIsDoneOnExactlyTheKillThatMeetsTheCount(): void
    {
        $id     = $this->accept();
        $target = (int)$this->repo->find($id, $this->player)['target_count'];

        for ($i = 1; $i < $target; $i++) {
            $this->repo->advanceKills($this->player, 'goblin');
            $this->assertSame('active', $this->repo->find($id, $this->player)['state'], "after $i kills");
        }

        $this->repo->advanceKills($this->player, 'goblin');
        $this->assertSame('done', $this->repo->find($id, $this->player)['state']);
    }

    public function testAFinishedHuntStopsCounting(): void
    {
        $id = $this->accept();
        for ($i = 0; $i < 7; $i++) {
            $this->repo->advanceKills($this->player, 'goblin');
        }

        $this->assertSame(5, (int)$this->repo->find($id, $this->player)['progress']);
    }

    public function testTheProofDemandedIsNamedAndCounted(): void
    {
        $id    = $this->accept();
        $proof = $this->repo->proofFor($this->player, $this->repo->find($id, $this->player));

        $this->assertSame(5, $proof['need']);
        $this->assertSame(0, $proof['have']);
        $this->assertNotSame('', $proof['item']);
    }

    public function testProofCollectedIsCounted(): void
    {
        $id   = $this->accept();
        $ear  = (int)Quest::TEMPLATES['clear_goblins']['proof_item_id'];
        $items = Repositories::get(ItemRepository::class);
        $items->grant($this->charId, $ear);
        $items->grant($this->charId, $ear);

        $proof = $this->repo->proofFor($this->player, $this->repo->find($id, $this->player));

        $this->assertSame(2, $proof['have']);
    }

    public function testAQuestNeedingNoProofDemandsNone(): void
    {
        $this->assertNull($this->repo->proofFor($this->player, ['template_key' => 'no_such_template']));
    }

    // Interrogation flags intel that bears on something the player already
    // cares about, so it has to know what they are currently hunting.
    public function testTheRepositoryKnowsWhichPeopleThePlayerIsHunting(): void
    {
        $this->assertFalse($this->repo->hasActiveKillQuest($this->player, 'goblin'));

        $id = $this->accept();
        $this->assertTrue($this->repo->hasActiveKillQuest($this->player, 'goblin'));
        $this->assertFalse($this->repo->hasActiveKillQuest($this->player, 'wolf'));

        $this->repo->markTurnedIn($id);
        $this->assertFalse($this->repo->hasActiveKillQuest($this->player, 'goblin'));
    }

    public function testATurnedInQuestLeavesTheActiveList(): void
    {
        $id = $this->accept();
        $this->assertCount(1, $this->repo->activeForPlayer($this->player));

        $this->repo->markTurnedIn($id);

        $this->assertSame([], $this->repo->activeForPlayer($this->player));
    }

    public function testAQuestIsOnlyVisibleToThePlayerHoldingIt(): void
    {
        $id    = $this->accept();
        $other = $this->makePlayer('bystander');

        $this->assertNotNull($this->repo->find($id, $this->player));
        $this->assertNull($this->repo->find($id, $other));
        $this->assertSame([], $this->repo->activeForPlayer($other));
    }

    public function testOnePlayersKillsDoNotAdvanceAnothersHunt(): void
    {
        $id    = $this->accept();
        $other = $this->makePlayer('rival');

        $this->repo->advanceKills($other, 'goblin');

        $this->assertSame(0, (int)$this->repo->find($id, $this->player)['progress']);
    }
}
