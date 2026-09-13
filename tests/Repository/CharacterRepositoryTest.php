<?php
declare(strict_types=1);

namespace Gob\Tests\Repository;

use Gob\Domain\Character;
use Gob\Domain\Tutor;
use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Tests\Support\DatabaseTestCase;

// The hero's persistence: creation with starter gear on, regeneration and
// finished lessons applied on read rather than by a worker, and the one
// tuition slot that locks the game down while it runs.
final class CharacterRepositoryTest extends DatabaseTestCase
{
    private CharacterRepository $repo;
    private int $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo   = Repositories::get(CharacterRepository::class);
        $this->player = $this->makePlayer('hero');
    }

    private function set(int $charId, array $fields): void
    {
        $sets = implode(', ', array_map(fn(string $k) => "$k = :$k", array_keys($fields)));
        $this->db->prepare("UPDATE characters SET $sets WHERE id = :id")
                 ->execute($fields + ['id' => $charId]);
    }

    private function skill(int $charId, string $skill): int
    {
        $stmt = $this->db->prepare('SELECT value FROM character_skills WHERE character_id = ? AND skill = ?');
        $stmt->execute([$charId, $skill]);
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    public function testANewHeroIsGivenEveryStartingSkill(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        foreach (Character::SKILLS as $s) {
            $this->assertSame(1, $this->skill($charId, $s), $s);
        }
    }

    // A new player must not walk into the first goblin fight bare-handed with
    // a sword they never noticed in the backpack.
    public function testANewHeroIsAlreadyWearingTheStarterGear(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $view   = $this->repo->load($charId)->toArray();

        $this->assertNotNull($view['equipment']['weapon']);
        $this->assertNotNull($view['equipment']['head']);
        $this->assertSame([], $view['inventory'], 'nothing was left in the backpack');
    }

    public function testAskingTwiceDoesNotBuildASecondHero(): void
    {
        $first  = $this->repo->ensure($this->player, 'Gareth');
        $second = $this->repo->ensure($this->player, 'Gareth');

        $this->assertSame($first, $second);
        $this->assertSame(1, (int)$this->db->query("SELECT COUNT(*) FROM characters WHERE player_id = {$this->player}")->fetchColumn());
    }

    public function testHealthReturnsWithTimePassed(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->set($charId, ['hp' => 10, 'hp_max' => 100, 'last_regen_at' => date('Y-m-d H:i:s', time() - 60)]);

        $this->repo->regen($charId);

        $this->assertSame(70, (int)$this->db->query("SELECT hp FROM characters WHERE id = $charId")->fetchColumn());
    }

    public function testHealingStopsAtTheEffectiveMaximum(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $bonus  = $this->repo->equippedHpBonus($charId);
        $this->set($charId, ['hp' => 10, 'hp_max' => 100, 'last_regen_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $this->repo->regen($charId);

        $this->assertSame(100 + $bonus, (int)$this->db->query("SELECT hp FROM characters WHERE id = $charId")->fetchColumn());
    }

    // Worn gear raises the ceiling healing climbs to, so a hero in a cap heals
    // past the bare hp_max column.
    public function testWornGearRaisesTheCeilingHealingClimbsTo(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->assertGreaterThan(0, $this->repo->equippedHpBonus($charId));
    }

    public function testCarriedGearRaisesNoCeiling(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->db->prepare('UPDATE character_items SET equipped_slot = NULL WHERE character_id = ?')->execute([$charId]);

        $this->assertSame(0, $this->repo->equippedHpBonus($charId));
    }

    public function testAHeroWhoHasNeverRestedJustStartsTheClock(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->set($charId, ['hp' => 10, 'last_regen_at' => null]);

        $this->repo->regen($charId);

        $this->assertSame(10, (int)$this->db->query("SELECT hp FROM characters WHERE id = $charId")->fetchColumn());
        $this->assertNotNull($this->db->query("SELECT last_regen_at FROM characters WHERE id = $charId")->fetchColumn());
    }

    public function testTheMercyStanceIsRememberedBetweenFights(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->assertFalse($this->repo->mercyStance($charId));
        $this->repo->setMercy($charId, true);
        $this->assertTrue($this->repo->mercyStance($charId));
        $this->repo->setMercy($charId, false);
        $this->assertFalse($this->repo->mercyStance($charId));
    }

    // The window remembers where the sparing happened, because that decides how
    // narrowly the deed is later remembered.
    public function testAnOpenWindowRemembersWhoWhereAndWhen(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->openMercyWindow($charId, 5, null, null);
        $w = $this->repo->mercyWindow($charId);

        $this->assertSame(5, $w['monster_id']);
        $this->assertFalse($w['expired']);
        $this->assertGreaterThan(0, $w['seconds_left']);
        $this->assertLessThanOrEqual(Character::MERCY_WINDOW_SECONDS, $w['seconds_left']);
    }

    // An expired window is still reported, flagged — the caller has to settle
    // it as a spare rather than have it vanish unrecorded.
    public function testAWindowWhoseTimeRanOutIsReportedAsExpiredNotAsAbsent(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->repo->openMercyWindow($charId, 5, null, null);
        $this->set($charId, ['spared_at' => date('Y-m-d H:i:s', time() - Character::MERCY_WINDOW_SECONDS - 5)]);

        $w = $this->repo->mercyWindow($charId);

        $this->assertNotNull($w);
        $this->assertTrue($w['expired']);
        $this->assertSame(0, $w['seconds_left']);
    }

    public function testNobodyAtYourMercyMeansNoWindow(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->assertNull($this->repo->mercyWindow($charId));

        $this->repo->openMercyWindow($charId, 5, null, null);
        $this->repo->clearMercyWindow($charId);

        $this->assertNull($this->repo->mercyWindow($charId));
    }

    public function testASkillTheHeroNeverHadStartsExistingWhenTaught(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->raiseSkill($charId, 'lang_goblin', 8);

        $this->assertSame(8, $this->skill($charId, 'lang_goblin'));
    }

    public function testTeachingTheSameSkillTwiceAddsUp(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->raiseSkill($charId, 'lang_goblin', 8);
        $this->repo->raiseSkill($charId, 'lang_goblin', 23);

        $this->assertSame(31, $this->skill($charId, 'lang_goblin'));
    }

    public function testNoSkillClimbsPastTheTopOfTheScale(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->raiseSkill($charId, 'lang_goblin', 90);
        $this->repo->raiseSkill($charId, 'lang_goblin', 90);

        $this->assertSame(100, $this->skill($charId, 'lang_goblin'));
    }

    public function testASingleHugeLessonIsAlsoCapped(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->raiseSkill($charId, 'lang_goblin', 400);

        $this->assertSame(100, $this->skill($charId, 'lang_goblin'));
    }

    public function testALessonInProgressIsReportedWithItsTimeLeft(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->startTraining($charId, 'lang_goblin', 12, 240);
        $t = $this->repo->training($charId);

        $this->assertSame('lang_goblin', $t['skill']);
        $this->assertSame(12, $t['gain']);
        $this->assertGreaterThan(230, $t['seconds_left']);
    }

    // A lesson lands whenever the player next looks — no worker, same as regen.
    public function testAFinishedLessonIsBankedOnTheNextRead(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->repo->startTraining($charId, 'lang_goblin', 12, 1);
        $this->set($charId, ['training_ends_at' => date('Y-m-d H:i:s', time() - 1)]);

        $view = $this->repo->load($charId)->toArray();

        $this->assertSame(12, $view['skills']['lang_goblin']);
        $this->assertNull($view['training'], 'the slot is free again');
    }

    public function testALessonStillRunningBanksNothing(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->repo->startTraining($charId, 'lang_goblin', 12, 600);

        $this->repo->settleTraining($charId);

        $this->assertSame(0, $this->skill($charId, 'lang_goblin'));
        $this->assertNotNull($this->repo->training($charId));
    }

    // Giving up keeps the part you actually sat through: a lesson is bought by
    // the point and studied at a fixed rate, so the interrupted share is just
    // the time you gave it. The fee stays with the tutor.
    public function testGivingUpHalfwayKeepsTheHalfYouSatThrough(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $gain   = 10;
        $this->repo->startTraining($charId, 'lang_goblin', $gain, $gain * Tutor::SECONDS_PER_POINT);
        $this->set($charId, [
            'training_ends_at' => date('Y-m-d H:i:s', time() + ($gain * Tutor::SECONDS_PER_POINT) / 2),
        ]);

        $banked = $this->repo->cancelTraining($charId);

        $this->assertSame('lang_goblin', $banked['skill']);
        $this->assertSame(5, $banked['learned']);
        $this->assertSame(5, $this->skill($charId, 'lang_goblin'));
        $this->assertNull($this->repo->training($charId));
    }

    public function testWalkingOutImmediatelyLearnsNothingButStillFreesTheSlot(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->repo->startTraining($charId, 'lang_goblin', 10, 10 * Tutor::SECONDS_PER_POINT);

        $banked = $this->repo->cancelTraining($charId);

        $this->assertSame(0, $banked['learned']);
        $this->assertSame(0, $this->skill($charId, 'lang_goblin'));
        $this->assertNull($this->repo->training($charId));
    }

    public function testGivingUpNeverBanksMoreThanTheLessonWasWorth(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');
        $this->repo->startTraining($charId, 'lang_goblin', 4, 4 * Tutor::SECONDS_PER_POINT);
        $this->set($charId, ['training_ends_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $banked = $this->repo->cancelTraining($charId);

        $this->assertSame(4, $banked['learned']);
    }

    public function testThereIsNothingToGiveUpWhenNoLessonIsRunning(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->assertNull($this->repo->cancelTraining($charId));
    }

    public function testTheExploreCooldownCountsDownAndThenClears(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->assertSame(0, $this->repo->exploreCooldownRemaining($charId, 30));

        $this->repo->stampExplore($charId);
        $this->assertGreaterThan(0, $this->repo->exploreCooldownRemaining($charId, 30));

        $this->set($charId, ['last_explore_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame(0, $this->repo->exploreCooldownRemaining($charId, 30));
    }

    public function testAPassiveRegenBonusIsRaisedAndNeverGoesNegative(): void
    {
        $charId = $this->repo->ensure($this->player, 'Gareth');

        $this->repo->adjustRegenBonus($charId, 10);
        $this->assertSame(10, (int)$this->db->query("SELECT regen_bonus FROM characters WHERE id = $charId")->fetchColumn());

        $this->repo->adjustRegenBonus($charId, -40);
        $this->assertSame(0, (int)$this->db->query("SELECT regen_bonus FROM characters WHERE id = $charId")->fetchColumn());
    }
}
