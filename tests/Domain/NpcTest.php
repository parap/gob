<?php
declare(strict_types=1);

namespace Gob\Tests\Domain;

use Gob\Domain\Npc;
use PHPUnit\Framework\TestCase;

// One concept for every notable person: village residents, and the spawns that
// stopped being spawns because the player chose not to kill them.
final class NpcTest extends TestCase
{
    private function npc(array $overrides = []): Npc
    {
        return new Npc($overrides + [
            'id'            => 1,
            'name'          => 'Scholar Yves',
            'race'          => 'human',
            'profession'    => 'scholar',
            'teaches'       => null,
            'teach_ceiling' => 0,
            'monster_id'    => null,
        ]);
    }

    public function testAVillageResidentCameFromNoMonster(): void
    {
        $this->assertFalse($this->npc()->isPromoted());
    }

    public function testAnIndividualPromotedOutOfASpawnRemembersWhatItWas(): void
    {
        $this->assertTrue($this->npc(['monster_id' => 1])->isPromoted());
    }

    public function testATutorNamesTheTongueTheyTeach(): void
    {
        $this->assertSame('goblin', $this->npc(['teaches' => 'goblin'])->teaches());
    }

    // An empty column means "teaches nothing", not "teaches the empty tongue" —
    // the training layer tests this for null.
    public function testSomeoneWhoTeachesNothingSaysSoAsNull(): void
    {
        $this->assertNull($this->npc(['teaches' => null])->teaches());
        $this->assertNull($this->npc(['teaches' => ''])->teaches());
    }

    public function testATutorsCeilingIsWhatTheyCanTakeAStudentTo(): void
    {
        $this->assertSame(28, $this->npc(['teach_ceiling' => 28])->teachCeiling());
        $this->assertSame(0, $this->npc()->teachCeiling());
    }

    // The village is seeded with four residents, only some of whom do anything;
    // the rest being present but idle is the point — nothing is pushed (§1).
    public function testTheVillageIsSeededWithItsFullRoster(): void
    {
        $professions = array_column(Npc::VILLAGE_ROSTER, 'profession');

        $this->assertSame(['elder', 'merchant', 'healer', 'scholar'], $professions);
        foreach (Npc::VILLAGE_ROSTER as $resident) {
            $this->assertNotSame('', $resident['name']);
            $this->assertSame('human', $resident['race']);
        }
    }

    // The scholar's Goblinish is never advertised — it is a column, not a pitch.
    public function testExactlyOneResidentTeachesAnything(): void
    {
        $teachers = array_filter(Npc::VILLAGE_ROSTER, fn(array $r) => $r['teaches'] !== null);

        $this->assertCount(1, $teachers);
        $this->assertSame('scholar', array_values($teachers)[0]['profession']);
        $this->assertSame('goblin', array_values($teachers)[0]['teaches']);
    }

    public function testAPromotedIndividualIsGivenAName(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $name = Npc::generateName();
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]*[a-z]+$/', $name);
            $this->assertGreaterThanOrEqual(3, strlen($name));
        }
    }

    public function testGeneratedNamesVary(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[Npc::generateName()] = true;
        }

        $this->assertGreaterThan(10, count($seen));
    }

    public function testThePayloadCarriesAnyOfferAndTuitionThePersonHas(): void
    {
        $payload = $this->npc()->toArray(['key' => 'clear_goblins'], ['gold' => 84]);

        $this->assertSame(['key' => 'clear_goblins'], $payload['offer']);
        $this->assertSame(['gold' => 84], $payload['tuition']);
    }

    // An idle resident is listed with nothing attached — the row simply says
    // "Ask", exactly like everyone else's.
    public function testAnIdleResidentIsListedWithNothingAttached(): void
    {
        $payload = $this->npc()->toArray();

        $this->assertNull($payload['offer']);
        $this->assertNull($payload['tuition']);
        $this->assertSame('Scholar Yves', $payload['name']);
    }

    public function testThePayloadNeverLeaksWhatATutorCouldTeach(): void
    {
        $payload = $this->npc(['teaches' => 'goblin', 'teach_ceiling' => 28])->toArray();

        $this->assertArrayNotHasKey('teaches', $payload);
        $this->assertArrayNotHasKey('teach_ceiling', $payload);
    }
}
