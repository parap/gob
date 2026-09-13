<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Monster;
use Gob\Repositories;
use Gob\Repository\MonsterRepository;
use Gob\Tests\Support\DatabaseTestCase;

// The monster catalogue. Shared by every player, and the source world
// generation draws a province's population from.
final class MonsterRepositoryTest extends DatabaseTestCase
{
    private MonsterRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = Repositories::get(MonsterRepository::class);
    }

    public function testTheCatalogueIsSeeded(): void
    {
        $all = $this->repo->all();

        $this->assertNotEmpty($all);
        $this->assertContainsOnlyInstancesOf(Monster::class, $all);
    }

    public function testTheCatalogueIsOrderedByLevel(): void
    {
        $levels = array_column(array_map(fn(Monster $m) => $m->toArray(), $this->repo->all()), 'level');
        $sorted = $levels;
        sort($sorted);

        $this->assertSame($sorted, $levels);
    }

    public function testAMonsterIsFoundByItsId(): void
    {
        $row = $this->repo->find(1);

        $this->assertNotNull($row);
        $this->assertSame(1, (int)$row['id']);
        $this->assertArrayHasKey('race', $row, 'combat reads the raw row');
    }

    public function testAMonsterThatDoesNotExistIsNotFound(): void
    {
        $this->assertNull($this->repo->find(999999));
    }

    public function testTagsRideAlongWithTheirMonster(): void
    {
        $tags = $this->repo->tags();

        $this->assertNotEmpty($tags);
        foreach ($this->repo->all() as $monster) {
            $view = $monster->toArray();
            $this->assertSame($tags[$view['id']] ?? [], $view['tags']);
        }
    }

    // Race, nature and nation are three separate axes; sparability keys off
    // nature, so it must never be blank.
    public function testEveryMonsterHasANatureToDecideSparabilityFrom(): void
    {
        foreach ($this->repo->all() as $monster) {
            $view = $monster->toArray();
            $this->assertNotSame('', $view['nature'], $view['name']);
            $this->assertNotSame('', $view['race'], $view['name']);
        }
    }

    public function testAProvinceIsPopulatedFromItsOwnThemeWhenItCan(): void
    {
        $ids = $this->repo->terrainCandidates(['goblin'], 1);

        $this->assertNotEmpty($ids);
        foreach ($ids as $id) {
            $this->assertSame('goblin', $this->repo->find($id)['race']);
        }
    }

    public function testCandidatesStayNearTheProvincesOwnLevel(): void
    {
        $level = 3;
        foreach ($this->repo->terrainCandidates(['goblin'], $level) as $id) {
            $monsterLevel = (int)$this->repo->find($id)['level'];
            $this->assertGreaterThanOrEqual(max(1, $level - 2), $monsterLevel);
            $this->assertLessThanOrEqual($level + 3, $monsterLevel);
        }
    }

    // A province must always be populatable: a theme nothing matches falls back
    // to anything in the level band rather than leaving the map empty.
    public function testAThemeNothingMatchesStillPopulatesTheProvince(): void
    {
        $ids = $this->repo->terrainCandidates(['nonesuch'], 1);

        $this->assertNotEmpty($ids);
    }

    public function testAnUnpeopledLevelBandStillYieldsSomething(): void
    {
        $ids = $this->repo->terrainCandidates([], 9999);

        $this->assertNotEmpty($ids);
    }

    public function testTheCatalogueIsTheSameForEveryPlayer(): void
    {
        $before = count($this->repo->all());
        $this->makePlayer('newcomer');

        $this->assertSame($before, count($this->repo->all()));
    }
}
