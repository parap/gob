<?php
declare(strict_types=1);

namespace Gob\Tests\Handler;

use Gob\Domain\Character;
use Gob\Domain\Relationship;
use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Repository\MonsterRepository;
use Gob\Repository\RelationshipRepository;
use Gob\Repository\SettlementRepository;
use Gob\Tests\Support\HandlerTestCase;

// Resolving a fight: the blow-by-blow, what a body is worth, what the mercy
// stance does to a beaten enemy, and what the winner's people do with a beaten
// hero.
final class CombatTest extends HandlerTestCase
{
    private int $player;
    private int $charId;
    private array $playerRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = $this->makePlayer('fighter');
        $this->charId = Repositories::get(CharacterRepository::class)->ensure($this->player, 'Gareth');
        Repositories::get(SettlementRepository::class)->createStarting($this->player);

        $stmt = $this->db->prepare('SELECT * FROM players WHERE id = ?');
        $stmt->execute([$this->player]);
        $this->playerRow = $stmt->fetch();
    }

    private function monster(int $id): array
    {
        return Repositories::get(MonsterRepository::class)->find($id);
    }

    private function goblin(): array
    {
        foreach (Repositories::get(MonsterRepository::class)->all() as $m) {
            $row = $m->row();
            if (($row['race'] ?? '') === 'goblin') {
                return $row;
            }
        }
        $this->fail('the catalogue has no goblin to fight');
    }

    private function setHp(int $hp): void
    {
        $this->db->prepare('UPDATE characters SET hp = ? WHERE id = ?')->execute([$hp, $this->charId]);
    }

    // --- damage ----------------------------------------------------------

    // Even a hopeless swing scratches: a fight that could deal zero would never
    // end, and the round cap would decide it instead.
    public function testEveryBlowLandsForSomething(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertGreaterThanOrEqual(1, combatDamage(1, 50, 50, 0));
        }
    }

    public function testDefenceAndArmourBothBluntABlow(): void
    {
        $bare    = 0;
        $armoured = 0;
        for ($i = 0; $i < 300; $i++) {
            $bare     += combatDamage(30, 0, 0, 0);
            $armoured += combatDamage(30, 10, 10, 0);
        }

        $this->assertGreaterThan($armoured, $bare);
    }

    // Penetration is what armour cannot answer, so it buys back exactly what
    // protection took away.
    public function testPenetrationBuysBackWhatArmourSoaked(): void
    {
        $soaked = 0;
        $pierced = 0;
        for ($i = 0; $i < 300; $i++) {
            $soaked  += combatDamage(30, 0, 10, 0);
            $pierced += combatDamage(30, 0, 10, 10);
        }

        $this->assertGreaterThan($soaked, $pierced);
    }

    // --- the mercy stance ------------------------------------------------

    public function testWithTheStanceOffNothingIsSpared(): void
    {
        $this->assertSame('off', mercyOutcome(false, $this->goblin()));
    }

    // Undead and constructs ignore the toggle: there is nobody there to spare,
    // which costs the player nothing and teaches that some things really are
    // just monsters.
    public function testSomeThingsReallyAreJustMonsters(): void
    {
        $statue = ['id' => 1, 'race' => 'none', 'nature' => 'construct'];

        for ($i = 0; $i < 30; $i++) {
            $this->assertSame('unsparable', mercyOutcome(true, $statue));
        }
    }

    public function testAnythingWithSomebodyHomeIsEitherSparedOrDiesFighting(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[mercyOutcome(true, $this->goblin())] = true;
        }

        $outcomes = array_keys($seen);
        sort($outcomes);

        $this->assertSame(['fanatic', 'spared'], $outcomes);
    }

    // --- winning ---------------------------------------------------------

    public function testAWonFightPaysGoldAndTrainsTheSkillsThatDidTheWork(): void
    {
        $settlements = Repositories::get(SettlementRepository::class);
        $before = $settlements->gold($this->player);
        $goblin = $this->goblin();

        $res = resolveFight($this->playerRow, $this->charId, $goblin);

        $this->assertSame('win', $res['outcome']);
        $this->assertSame((int)$goblin['reward_gold'], $res['rewards']['gold']);
        $this->assertGreaterThan($before, $settlements->gold($this->player));
        $this->assertContains('attack', $res['rewards']['skills']);
    }

    public function testAGoblinAlwaysYieldsTheEarAQuestAsksFor(): void
    {
        $res = resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertContains('Goblin Ear', $res['rewards']['items']);
    }

    public function testKillingRaisesThatPeoplesHostility(): void
    {
        $relations = Repositories::get(RelationshipRepository::class);
        $before = $relations->effective($this->player, 'goblin', null, null)->hostility();

        resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertGreaterThan($before, $relations->effective($this->player, 'goblin', null, null)->hostility());
    }

    // Mercy costs loot, not practice: you fought either way.
    public function testSparingCostsTheLootButNotTheTraining(): void
    {
        Repositories::get(CharacterRepository::class)->setMercy($this->charId, true);
        $settlements = Repositories::get(SettlementRepository::class);

        $spared = null;
        for ($i = 0; $i < 40 && $spared === null; $i++) {
            $this->setHp(1000);
            $before = $settlements->gold($this->player);
            $res = resolveFight($this->playerRow, $this->charId, $this->goblin());
            if ($res['mercy']['outcome'] === 'spared') {
                $spared = [$res, $before];
            }
        }
        $this->assertNotNull($spared, 'never drew a spare in forty fights');
        [$res, $before] = $spared;

        $this->assertSame(0, $res['rewards']['gold']);
        $this->assertSame([], $res['rewards']['items']);
        $this->assertSame($before, $settlements->gold($this->player));
        $this->assertNotEmpty($res['rewards']['skills']);
        $this->assertGreaterThan(0, $res['mercy']['forgone_gold']);
        $this->assertNotNull($res['mercy']['window']);
    }

    // The deed waits on the window: right now the enemy is only beaten, not
    // yet spared, so nothing about the relationship has moved.
    public function testTheSparingIsNotRecordedUntilTheWindowResolves(): void
    {
        Repositories::get(CharacterRepository::class)->setMercy($this->charId, true);
        $relations = Repositories::get(RelationshipRepository::class);

        for ($i = 0; $i < 40; $i++) {
            $this->setHp(1000);
            // Read it immediately before the fight: the fights this loop
            // discards are kills, and each of those moves the number too.
            $before = $relations->effective($this->player, 'goblin', null, null)->hostility();
            $res = resolveFight($this->playerRow, $this->charId, $this->goblin());
            if ($res['mercy']['outcome'] !== 'spared') {
                continue;
            }
            $this->assertSame($before, $relations->effective($this->player, 'goblin', null, null)->hostility());

            settleMercyWindow($this->player, $this->charId, true);
            $this->assertLessThan($before, $relations->effective($this->player, 'goblin', null, null)->hostility());
            return;
        }
        $this->fail('never drew a spare in forty fights');
    }

    // Sparing is capped at wary coexistence: no number of spared goblins turns
    // them friendly, because liking has to be earned by helping and no verb
    // does that yet. The cap is applied where the deed is banked, so it is
    // checked there rather than only in the repository.
    public function testNoAmountOfSparingCarriesAPeoplePastWaryCoexistence(): void
    {
        $relations = Repositories::get(RelationshipRepository::class);
        $goblinId  = (int)$this->goblin()['id'];

        for ($i = 0; $i < 80; $i++) {
            Repositories::get(CharacterRepository::class)->openMercyWindow($this->charId, $goblinId, null, null);
            settleMercyWindow($this->player, $this->charId, true);
        }

        $rel = $relations->effective($this->player, 'goblin', null, null);
        $this->assertSame(Relationship::SPARE_HOSTILITY_FLOOR, $rel->hostility());
        $this->assertSame(Relationship::STAGE_NEUTRAL, $rel->stageIndex());
        $this->assertSame(0, $rel->trust(), 'sparing never buys liking');
    }

    public function testAWindowStillRunningIsNotSettledByAccident(): void
    {
        $characters = Repositories::get(CharacterRepository::class);
        $characters->openMercyWindow($this->charId, (int)$this->goblin()['id'], null, null);

        settleMercyWindow($this->player, $this->charId);

        $this->assertNotNull($characters->mercyWindow($this->charId), 'the window is still open');
    }

    // --- losing ----------------------------------------------------------

    public function testALostFightLeavesTheHeroAliveAndSaysWhoDecidedThat(): void
    {
        $this->setHp(1);

        $res = resolveFight($this->playerRow, $this->charId, $this->monster(5) ?? $this->goblin());

        $this->assertSame('loss', $res['outcome']);
        $this->assertGreaterThanOrEqual(1, $res['hero_hp_after']);
        $this->assertContains($res['loss']['outcome'], [
            Relationship::LOSS_ROBBED, Relationship::LOSS_SPARED, Relationship::LOSS_HELPED,
        ]);
    }

    // Nobody is home in a risen corpse, so a loss to one is always the harsh
    // outcome however the numbers read.
    public function testLosingToSomethingWithNobodyHomeIsAlwaysTheHarshOutcome(): void
    {
        $this->setHp(1);
        $statue = $this->goblin();
        $statue['race']    = 'none';
        $statue['nature']  = 'construct';
        $statue['attack']  = 999;
        $statue['hp']      = 999;

        for ($i = 0; $i < 10; $i++) {
            $this->setHp(1);
            $res = resolveFight($this->playerRow, $this->charId, $statue);
            $this->assertSame(Relationship::LOSS_ROBBED, $res['loss']['outcome']);
        }
    }

    public function testBeingRobbedCostsGoldAndCarriesTheHeroHome(): void
    {
        $world = Repositories::get(\Gob\Repository\WorldRepository::class);
        $home  = $world->createProvince($this->player, 'Red Downs', 'plains', 1, true);
        $away  = $world->createProvince($this->player, 'Far Reach', 'forest', 2, false);
        $world->setCurrentProvince($this->charId, $away);

        $settlements = Repositories::get(SettlementRepository::class);
        $this->db->prepare('UPDATE settlements SET gold = 1000, rate_gold_per_hour = 0 WHERE player_id = ?')
                 ->execute([$this->player]);

        $statue = $this->goblin();
        $statue['race'] = 'none';
        $statue['nature'] = 'construct';
        $statue['attack'] = 999;
        $statue['hp'] = 999;
        $this->setHp(1);

        $res = resolveFight($this->playerRow, $this->charId, $statue);

        $this->assertSame(Relationship::LOSS_ROBBED, $res['loss']['outcome']);
        $this->assertGreaterThan(0, $res['loss']['gold_lost']);
        $this->assertTrue($res['loss']['carried_home']);
        $this->assertSame($home, $world->currentProvinceId($this->charId));
        $this->assertSame(1000 - $res['loss']['gold_lost'], $settlements->gold($this->player));
    }

    public function testAPeopleThatHasStoppedWantingYouDeadLeavesYouBreathing(): void
    {
        $this->db->prepare('REPLACE INTO rel_generic (player_id, race, hostility, trust) VALUES (?, ?, ?, ?)')
                 ->execute([$this->player, 'goblin', 10, 80]);

        $brute = $this->goblin();
        $brute['attack'] = 999;
        $brute['hp'] = 999;

        for ($i = 0; $i < 40; $i++) {
            $this->setHp(1);
            $res = resolveFight($this->playerRow, $this->charId, $brute);
            if ($res['loss']['outcome'] === Relationship::LOSS_HELPED) {
                $this->assertSame(0, $res['loss']['gold_lost']);
                $this->assertGreaterThan(1, $res['loss']['hp']);
                return;
            }
        }
        $this->fail('an allied people never once patched the hero up in forty losses');
    }

    // --- the attitude reported alongside a fight -------------------------

    public function testAFightReportsHowThatPeopleNowSeeYou(): void
    {
        $res = resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertSame('goblin', $res['relation']['race']);
        $this->assertArrayHasKey('stage_label', $res['relation']);
    }

    public function testAStatueHasNoOpinionToReport(): void
    {
        $this->assertNull(relationView($this->player, ['race' => 'none'], null, null));
    }

    // Meeting something teaches you a little about it, and the journal keeps it.
    public function testMeetingACreatureTeachesYouSomethingAboutIt(): void
    {
        $res = resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertNotEmpty($res['facts'], 'an encounter always teaches something');
    }

    public function testStartingAFightAbandonsAnyoneStillLyingAtYourMercy(): void
    {
        $characters = Repositories::get(CharacterRepository::class);
        $relations  = Repositories::get(RelationshipRepository::class);
        $before     = $relations->effective($this->player, 'goblin', null, null)->hostility();
        $characters->openMercyWindow($this->charId, (int)$this->goblin()['id'], null, null);

        $this->setHp(1000);
        resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertLessThan(
            $before + Relationship::KILL_HOSTILITY_RISE,
            $relations->effective($this->player, 'goblin', null, null)->hostility(),
            'the abandoned spare was banked before the new kill',
        );
    }

    public function testAFightIsAlwaysDecidedWithinTheRoundCap(): void
    {
        $res = resolveFight($this->playerRow, $this->charId, $this->goblin());

        $this->assertLessThanOrEqual(MAX_COMBAT_ROUNDS, $res['rounds']);
        $this->assertNotEmpty($res['log']);
    }
}
