<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Repositories;
use Gob\Repository\InfoRepository;
use Gob\Tests\Support\DatabaseTestCase;

// The journal. Facts are authored once and shared by every player; only what
// each player has noticed is per-player.
final class InfoRepositoryTest extends DatabaseTestCase
{
    private InfoRepository $repo;
    private int $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(InfoRepository::class);
        $this->player = $this->makePlayer('scholar');
    }

    private function goblinFactIds(): array
    {
        return array_map(fn($f) => $f->id(), $this->repo->factsFor('race', 'goblin'));
    }

    public function testTheGoblinsHaveAuthoredFactsToFind(): void
    {
        $facts = $this->repo->factsFor('race', 'goblin');

        $this->assertNotEmpty($facts, 'the seeded goblin facts are missing');
        $this->assertSame(count($facts), $this->repo->countFor('race', 'goblin'));
    }

    public function testASubjectNobodyWroteAboutHasNothingToFind(): void
    {
        $this->assertSame([], $this->repo->factsFor('race', 'nobody'));
        $this->assertSame(0, $this->repo->countFor('race', 'nobody'));
    }

    // Facts are authored cheapest-first, which is what makes a low perception
    // pace discovery instead of locking anything away.
    public function testFactsComeBackInTheirAuthoredOrder(): void
    {
        $ids = $this->goblinFactIds();
        $sorted = $ids;
        sort($sorted);

        $this->assertSame($sorted, $ids);
    }

    public function testAPlayerStartsKnowingNothing(): void
    {
        $this->assertSame([], $this->repo->knownIds($this->player));
        $this->assertSame([], $this->repo->journal($this->player));
    }

    public function testNoticingSomethingPutsItInTheJournal(): void
    {
        $ids = array_slice($this->goblinFactIds(), 0, 2);

        $this->repo->remember($this->player, $ids);

        $known = $this->repo->knownIds($this->player);
        foreach ($ids as $id) {
            $this->assertArrayHasKey($id, $known);
        }
        $this->assertCount(2, $this->repo->journal($this->player));
    }

    public function testNoticingNothingWritesNothing(): void
    {
        $this->repo->remember($this->player, []);

        $this->assertSame([], $this->repo->knownIds($this->player));
    }

    // Meeting the same creature again must not create a second journal entry,
    // and must not demote a fact the player has already done something with.
    public function testNoticingTheSameThingTwiceLeavesOneEntry(): void
    {
        $id = $this->goblinFactIds()[0];

        $this->repo->remember($this->player, [$id]);
        $this->repo->remember($this->player, [$id]);

        $this->assertCount(1, $this->repo->journal($this->player));
    }

    public function testAnAlreadySharedFactIsNotDemotedByMeetingItAgain(): void
    {
        $id = $this->goblinFactIds()[0];
        $this->repo->remember($this->player, [$id]);
        $this->db->prepare('UPDATE player_knowledge SET state = "shared" WHERE player_id = ? AND fact_id = ?')
                 ->execute([$this->player, $id]);

        $this->repo->remember($this->player, [$id]);

        $state = $this->db->query(
            "SELECT state FROM player_knowledge WHERE player_id = {$this->player} AND fact_id = $id"
        )->fetchColumn();
        $this->assertSame('shared', $state);
    }

    // Once you have noticed something you do not un-notice it, even if the
    // relationship that revealed it has since soured.
    public function testWhatWasNoticedStaysRememberedHoweverThingsChangeLater(): void
    {
        $ids = array_slice($this->goblinFactIds(), 0, 3);
        $this->repo->remember($this->player, $ids);

        $remembered = $this->repo->rememberedFor($this->player, 'race', 'goblin');

        $this->assertCount(3, $remembered);
        $this->assertSame($ids, array_map('intval', array_column($remembered, 'id')));
    }

    public function testTheJournalOnlyListsTheSubjectAsked(): void
    {
        $this->repo->remember($this->player, $this->goblinFactIds());

        $this->assertSame([], $this->repo->rememberedFor($this->player, 'race', 'wolf'));
    }

    // "4 of 11 about goblins" — the count must not leak what the other seven
    // are, only that they exist.
    public function testTheJournalCanShowProgressWithoutNamingTheRest(): void
    {
        $total = $this->repo->countFor('race', 'goblin');
        $this->repo->remember($this->player, array_slice($this->goblinFactIds(), 0, 2));

        $this->assertGreaterThan(2, $total);
        $this->assertCount(2, $this->repo->rememberedFor($this->player, 'race', 'goblin'));
    }

    public function testOnePlayersJournalIsInvisibleToAnother(): void
    {
        $other = $this->makePlayer('rival_scholar');
        $this->repo->remember($this->player, $this->goblinFactIds());

        $this->assertSame([], $this->repo->knownIds($other));
        $this->assertSame([], $this->repo->rememberedFor($other, 'race', 'goblin'));
    }

    public function testFactsThemselvesAreSharedByEverybody(): void
    {
        $other = $this->makePlayer('other_scholar');

        $this->assertSame($this->goblinFactIds(), array_map(
            fn($f) => $f->id(),
            $this->repo->factsFor('race', 'goblin'),
        ));
        $this->assertGreaterThan(0, $this->repo->countFor('race', 'goblin'));
        $this->assertSame([], $this->repo->knownIds($other));
    }

    public function testEveryAuthoredFactCarriesItsChannelAndContent(): void
    {
        foreach ($this->repo->factsFor('race', 'goblin') as $fact) {
            $view = $fact->toArray();
            $this->assertNotSame('', $view['content']);
            $this->assertNotSame('', $view['channel']);
            $this->assertNotSame('', $view['label']);
        }
    }
}
