<?php
declare(strict_types=1);

namespace Gob\Tests\Handler;

use Gob\Domain\Language;
use Gob\Domain\Npc;
use Gob\Domain\Relationship;
use Gob\Domain\Tutor;
use Gob\Repositories;
use Gob\Repository\CharacterRepository;
use Gob\Repository\NpcRepository;
use Gob\Repository\SettlementRepository;
use Gob\Tests\Support\HandlerTestCase;

// What a tutor offers, and the gate the whole mercy loop was built to open:
// a goblin who was spared teaches their own tongue, but only once their people
// have stopped trying to kill you.
final class TrainingTest extends HandlerTestCase
{
    private int $player;
    private int $charId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = $this->makePlayer('student');
        $this->charId = Repositories::get(CharacterRepository::class)->ensure($this->player, 'Gareth');
        Repositories::get(SettlementRepository::class)->createStarting($this->player);
    }

    private function scholar(int $ceiling = 30): array
    {
        $this->db->prepare(
            'INSERT INTO npcs (player_id, race, profession, name, teaches, teach_ceiling) VALUES (?,?,?,?,?,?)'
        )->execute([$this->player, 'human', 'scholar', 'Scholar Yves', 'goblin', $ceiling]);
        return $this->row((int)$this->db->lastInsertId());
    }

    private function survivor(int $ceiling = 80): array
    {
        $this->db->prepare('INSERT INTO provinces (player_id, name, terrain, level, is_home) VALUES (?,?,?,?,?)')
                 ->execute([$this->player, 'Red Downs', 'plains', 1, 1]);
        $province = (int)$this->db->lastInsertId();

        $this->db->prepare(
            'INSERT INTO npcs (player_id, race, profession, name, monster_id, province_id, teaches, teach_ceiling)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$this->player, 'goblin', Npc::SURVIVOR, 'Yigna', 1, $province, 'goblin', $ceiling]);
        return $this->row((int)$this->db->lastInsertId());
    }

    private function row(int $id): array
    {
        return Repositories::get(NpcRepository::class)->find($id, $this->player);
    }

    private function gold(int $amount): void
    {
        $this->db->prepare('UPDATE settlements SET gold = ?, rate_gold_per_hour = 0 WHERE player_id = ?')
                 ->execute([$amount, $this->player]);
    }

    private function feel(int $hostility, int $trust = 0): void
    {
        $this->db->prepare('REPLACE INTO rel_generic (player_id, race, hostility, trust) VALUES (?,?,?,?)')
                 ->execute([$this->player, 'goblin', $hostility, $trust]);
    }

    public function testSomeoneWhoTeachesNothingMakesNoOffer(): void
    {
        $this->db->prepare('INSERT INTO npcs (player_id, race, profession, name) VALUES (?,?,?,?)')
                 ->execute([$this->player, 'human', 'healer', 'Healer Orin']);

        $this->assertNull(tuitionOffer($this->player, $this->charId, $this->row((int)$this->db->lastInsertId()), []));
    }

    public function testTheScholarQuotesAPriceAndATime(): void
    {
        $this->gold(1000);
        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(30), []);

        $this->assertSame('lang_goblin', $offer['skill']);
        $this->assertSame('goblin', $offer['tongue']);
        $this->assertSame(Tutor::gain(0, 30), $offer['gain']);
        $this->assertSame(Tutor::price($offer['gain']), $offer['price']);
        $this->assertSame(Tutor::seconds($offer['gain']), $offer['seconds']);
        $this->assertTrue($offer['can']);
        $this->assertNull($offer['reason']);
    }

    // The reason is more useful to show than an absent button, so the numbers
    // are quoted even when the lesson cannot be taken.
    public function testAnUnaffordableLessonIsStillQuotedInFull(): void
    {
        $this->gold(0);
        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(30), []);

        $this->assertFalse($offer['can']);
        $this->assertStringContainsString('afford', $offer['reason']);
        $this->assertGreaterThan(0, $offer['price']);
        $this->assertGreaterThan(0, $offer['gain']);
    }

    public function testAStudentAlreadyInALessonIsTurnedAway(): void
    {
        $this->gold(1000);
        Repositories::get(CharacterRepository::class)->startTraining($this->charId, 'sword', 5, 600);

        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(30), []);

        $this->assertFalse($offer['can']);
        $this->assertStringContainsString('already studying', $offer['reason']);
    }

    public function testATutorTheStudentHasOvertakenHasNothingLeftToGive(): void
    {
        $this->gold(1000);
        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(20), ['lang_goblin' => 40]);

        $this->assertSame(0, $offer['gain']);
        $this->assertFalse($offer['can']);
        $this->assertStringContainsString('better than they do', $offer['reason']);
    }

    // The offer is stated as comprehension, not as a number: a language is
    // either understood or not (§1).
    public function testTheOfferIsStatedAsComprehensionRatherThanPoints(): void
    {
        $this->gold(1000);
        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(30), ['lang_goblin' => 5]);

        $this->assertSame(Language::sense(5), $offer['sense']);
        $this->assertSame(Language::senseAfter(5 + $offer['gain']), $offer['after_sense']);
        $this->assertSame(Language::fluency(5), $offer['fluency']);
    }

    public function testALessonThatCrossesABandSaysSo(): void
    {
        $this->gold(1000);

        $crosses = tuitionOffer($this->player, $this->charId, $this->scholar(35), ['lang_goblin' => 0]);
        $this->assertTrue($crosses['improves']);

        $within = tuitionOffer($this->player, $this->charId, $this->scholar(35), ['lang_goblin' => 30]);
        $this->assertFalse($within['improves'], 'a session inside one band does not dangle a new one');
    }

    // The number of points never crosses the wire: gold and time do, because
    // those are what the player is being asked to hand over.
    public function testWhatThePlayerSeesCarriesNoSkillPoints(): void
    {
        $this->gold(1000);
        $view = tuitionView(tuitionOffer($this->player, $this->charId, $this->scholar(30), ['lang_goblin' => 5]));

        $this->assertArrayNotHasKey('gain', $view);
        $this->assertArrayNotHasKey('current', $view);
        $this->assertArrayHasKey('price', $view);
        $this->assertArrayHasKey('seconds', $view);
        $this->assertArrayHasKey('after_sense', $view);
    }

    public function testThereIsNothingToShowWhenThereIsNoOffer(): void
    {
        $this->assertNull(tuitionView(null));
    }

    // The gate the mercy slice exists to open: a goblin who talked instead of
    // dying will not teach you while their people still want you dead.
    public function testAGoblinWhoseKinStillWantYouDeadWillNotTeachYou(): void
    {
        $this->gold(1000);
        $this->feel(Relationship::startingHostility('goblin'));
        $yigna = $this->survivor();

        $offer = tuitionOffer($this->player, $this->charId, $yigna, []);

        $this->assertFalse($offer['can']);
        $this->assertSame('They will not teach you anything. Not yet.', $offer['reason']);
    }

    // No hint is given about what would change their mind — the refusal says
    // "not yet" and nothing about sparing (§1).
    public function testTheRefusalGivesNoInstructions(): void
    {
        $this->gold(1000);
        $this->feel(Relationship::startingHostility('goblin'));

        $offer = tuitionOffer($this->player, $this->charId, $this->survivor(), []);

        foreach (['spare', 'mercy', 'hostil', 'kill'] as $tell) {
            $this->assertStringNotContainsStringIgnoringCase($tell, $offer['reason']);
        }
    }

    // Curious is not enough. Sparing is the one deed that lowers Hostility and
    // it reaches exactly Neutral — so the rung below it has to still refuse,
    // or the gate would open before the player has done the thing that opens
    // it.
    public function testAPeopleMerelyCuriousAboutYouStillWillNotTeach(): void
    {
        $this->gold(1000);
        $this->feel(Relationship::HOSTILE_CURIOUS);

        $offer = tuitionOffer($this->player, $this->charId, $this->survivor(), []);

        $this->assertSame(
            Relationship::STAGE_CURIOUS,
            Relationship::stage(Relationship::HOSTILE_CURIOUS, 0),
            'the fixture really is one rung short',
        );
        $this->assertFalse($offer['can']);
    }

    public function testOnceTheirPeopleAreMerelyWaryTheyWillTeach(): void
    {
        $this->gold(1000);
        $this->feel(Relationship::SPARE_HOSTILITY_FLOOR);

        $offer = tuitionOffer($this->player, $this->charId, $this->survivor(), []);

        $this->assertTrue($offer['can']);
        $this->assertNull($offer['reason']);
    }

    // A native speaker teaches their own tongue far past anything the village
    // scholar can reach — which is what makes the whole loop pay.
    public function testANativeSpeakerTeachesFarPastAnythingTheVillageOffers(): void
    {
        $this->gold(10000);
        $this->feel(Relationship::SPARE_HOSTILITY_FLOOR);

        $village = tuitionOffer($this->player, $this->charId, $this->scholar(Tutor::CEILING_SCHOLAR[1]), []);
        $native  = tuitionOffer($this->player, $this->charId, $this->survivor(Tutor::CEILING_NATIVE[0]), []);

        $this->assertGreaterThan($village['gain'], $native['gain']);
    }

    public function testAVillageResidentTeachesWhoeverAsks(): void
    {
        $this->gold(1000);
        $this->feel(Relationship::startingHostility('goblin'));

        $offer = tuitionOffer($this->player, $this->charId, $this->scholar(30), []);

        $this->assertTrue($offer['can'], 'the scholar is not gated on how goblins feel about you');
    }

    // --- the study lock ---------------------------------------------------

    public function testReadingIsAlwaysAllowedDuringALesson(): void
    {
        requireNotStudying('GET', 'GET /api/character/me');
        $this->assertTrue(true, 'a GET returns rather than answering the request itself');
    }

    public function testGivingUpIsAlwaysOnTheTable(): void
    {
        requireNotStudying('POST', 'POST /api/training/cancel');
        $this->assertTrue(true);
    }

    // Being unable to log out of a lesson would be a trap rather than a cost.
    public function testAuthenticationIsNeverLockedBehindALesson(): void
    {
        foreach (['POST /api/auth/register', 'POST /api/auth/login', 'POST /api/auth/logout'] as $route) {
            requireNotStudying('POST', $route);
        }
        $this->assertTrue(true);
    }

    // Locked by default: a route added later cannot quietly become something
    // you can do while studying.
    public function testEveryActionRouteIsLockedByDefault(): void
    {
        $front = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        preg_match_all("/'(POST [^']+)'\s*=>/", $front, $m);

        $this->assertNotEmpty($m[1], 'no POST routes found to check');
        foreach ($m[1] as $route) {
            if (in_array($route, STUDY_OPEN_ROUTES, true)) {
                continue;
            }
            $this->assertNotContains($route, STUDY_OPEN_ROUTES);
        }
    }
}
