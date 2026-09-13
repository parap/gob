<?php
declare(strict_types=1);

namespace Gob\Tests\Api;

use Gob\Domain\Tutor;
use Gob\Tests\Support\ApiTestCase;

// A lesson is somewhere you are, not a timer running in the background: while
// one is on, the game is locked down to looking at your character. The refusal
// is delivered by a handler that ends the request, so it is observed from
// outside the process rather than by calling the gate.
final class StudyLockTest extends ApiTestCase
{
    private function study(string $token, int $seconds): void
    {
        $charId = $this->characterIdOf($token);
        $this->liveDb()->prepare(
            'UPDATE characters
             SET training_skill = ?, training_gain = ?, training_ends_at = NOW() + INTERVAL ' . (int)$seconds . ' SECOND
             WHERE id = ?'
        )->execute(['lang_goblin', 5, $charId]);
    }

    public function testWithNoLessonRunningTheGameIsPlayable(): void
    {
        $token = $this->newPlayer();

        $res = $this->request('POST', '/api/combat/attack', ['monster_id' => 1], $token);

        $this->assertSame(200, $res['status'], $res['raw']);
    }

    // Locked by default: every action route is refused, not a listed few.
    public function testALessonRefusesEveryActionRoute(): void
    {
        $token = $this->newPlayer();
        $this->study($token, 600);

        foreach ([
            ['POST', '/api/combat/attack', ['monster_id' => 1]],
            ['POST', '/api/world/explore', null],
            ['POST', '/api/character/mercy', ['on' => true]],
            ['POST', '/api/quests/accept', ['npc_id' => 1]],
            ['POST', '/api/training/start', ['npc_id' => 1]],
        ] as [$method, $path, $payload]) {
            $res = $this->request($method, $path, $payload, $token);

            $this->assertSame(400, $res['status'], "$path was not refused: " . $res['raw']);
            $this->assertSame('You are in the middle of a lesson.', $res['body']['error'] ?? null, $path);
        }
    }

    // Reading is never locked — the character sheet needs its own data, and
    // blanking the screen would be a cost paid for nothing.
    public function testALessonNeverLocksReading(): void
    {
        $token = $this->newPlayer();
        $this->study($token, 600);

        foreach (['/api/character/me', '/api/world', '/api/monsters', '/api/knowledge', '/api/relations'] as $path) {
            $this->assertSame(200, $this->request('GET', $path, null, $token)['status'], $path);
        }
    }

    // Giving up is always on the table: a lock you cannot leave is a trap
    // rather than a cost.
    public function testGivingUpIsAlwaysOnTheTable(): void
    {
        $token = $this->newPlayer();
        $this->study($token, 10 * Tutor::SECONDS_PER_POINT);

        $res = $this->request('POST', '/api/training/cancel', null, $token);

        $this->assertSame(200, $res['status'], $res['raw']);
        $this->assertSame(200, $this->request('POST', '/api/combat/attack', ['monster_id' => 1], $token)['status']);
    }

    public function testLoggingOutOfALessonIsAlwaysPossible(): void
    {
        $token = $this->newPlayer();
        $this->study($token, 600);

        $this->assertSame(200, $this->request('POST', '/api/auth/logout', null, $token)['status']);
    }

    // A lesson whose time is up is banked on the next request rather than
    // continuing to lock the game.
    public function testALessonWhoseTimeIsUpStopsLockingAnything(): void
    {
        $token = $this->newPlayer();
        $this->study($token, 600);
        $charId = $this->characterIdOf($token);
        $this->liveDb()->prepare('UPDATE characters SET training_ends_at = NOW() - INTERVAL 1 SECOND WHERE id = ?')
                       ->execute([$charId]);

        $res = $this->request('POST', '/api/combat/attack', ['monster_id' => 1], $token);

        $this->assertSame(200, $res['status'], $res['raw']);
    }

    public function testAnUnauthenticatedRequestIsRefusedBeforeAnythingElse(): void
    {
        $this->assertSame(401, $this->request('GET', '/api/character/me')['status']);
        $this->assertSame(401, $this->request('POST', '/api/combat/attack', ['monster_id' => 1])['status']);
    }

    public function testAnUnknownRouteIsNotFound(): void
    {
        $this->assertSame(404, $this->request('GET', '/api/nothing-here')['status']);
    }
}
