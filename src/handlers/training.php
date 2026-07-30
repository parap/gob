<?php
declare(strict_types=1);

use Gob\Domain\Language;
use Gob\Domain\Npc;
use Gob\Domain\Relationship;
use Gob\Domain\Tutor;

// Tuition: paying a tutor to teach you a tongue (future_implementation.md §5).
//
// Training is a timed job with a single slot — you pay up front and the skill
// lands when the lesson finishes. Nothing here is advertised: the scholar
// mentions no languages and the UI makes no suggestion. A player who never
// asks never finds out, which is the point (§1).
//
// A lesson is where you are and what you are doing: while one runs the player
// can look at their character and nothing else. Giving up is always available,
// and costs the fee but not the part they sat through.

// The routes that stay open mid-lesson. Everything read-only (GET) is allowed
// on top of these — the character sheet needs its own data, and locking reads
// would only blank the screen. Authentication is exempt because being unable to
// log out of a lesson would be a trap rather than a cost.
const STUDY_OPEN_ROUTES = [
    'POST /api/auth/register',
    'POST /api/auth/login',
    'POST /api/auth/logout',
    'POST /api/training/cancel',
];

// The single gate. Called by the router before any handler runs, so a route is
// locked by default and has to be listed above to work during a lesson — a new
// action cannot quietly become something you can do while studying.
function requireNotStudying(string $method, string $route): void
{
    if ($method === 'GET' || in_array($route, STUDY_OPEN_ROUTES, true)) {
        return;
    }
    // Not logged in: let the handler answer with its own 401.
    $player = currentPlayer();
    if (!$player) {
        return;
    }

    $charId = ensureCharacter((int)$player['id'], (string)$player['username']);
    loadCharacter($charId);          // banks a lesson whose time is already up
    $lesson = characterRepo()->training($charId);
    if ($lesson !== null && $lesson['seconds_left'] > 0) {
        json(400, ['error' => 'You are in the middle of a lesson.']);
    }
}

// What this NPC would teach the player right now, or null if they teach
// nothing. Always returns the numbers, even when the lesson can't be taken —
// the reason is more useful to show than an absent button.
function tuitionOffer(int $playerId, int $charId, array $npcRow, array $skills): ?array
{
    $npc     = new Npc($npcRow);
    $teaches = $npc->teaches();
    if ($teaches === null) {
        return null;
    }

    $skill   = Language::skill($teaches);
    $current = (int)($skills[$skill] ?? 0);
    $ceiling = $npc->teachCeiling();
    $gain    = Tutor::gain($current, $ceiling);
    $price   = Tutor::price($gain);
    $busy    = characterRepo()->training($charId) !== null;
    $gold    = settlementRepo()->gold($playerId);

    // A promoted individual teaches their own tongue far better than any human
    // scholar — but only once their people have stopped trying to kill you
    // (§5). This is the gate the whole mercy slice was built to open: spare
    // goblins until they are merely wary, and one of them will talk to you.
    // No hint is given about what would change their mind.
    $reason = match (true) {
        $npc->isPromoted() && !willTeach($playerId, $npc, $npcRow)
                       => 'They will not teach you anything. Not yet.',
        $gain <= 0     => 'You already speak it better than they do.',
        $busy          => 'You are already studying something else.',
        $gold < $price => "You cannot afford the fee ({$price}g).",
        default        => null,
    };

    // Where the lesson would leave you, in words. When it lands in the same
    // band as now the session is still worth taking — it just does not move you
    // into a new one, which is what 'improves' says.
    $improves = Language::fluency($current + $gain) !== Language::fluency($current);

    return [
        'skill'       => $skill,
        'label'       => Language::label($skill),
        'tongue'      => $teaches,
        'current'     => $current,
        'fluency'     => Language::fluency($current),
        'sense'       => Language::sense($current),
        'after_sense' => Language::senseAfter($current + $gain),
        'improves'    => $improves,
        'gain'        => $gain,
        'price'       => $price,
        'seconds'     => Tutor::seconds($gain),
        'can'         => $reason === null,
        'reason'      => $reason,
    ];
}

// The offer as the player may see it: the same thing minus the skill points,
// which are the one part of it that is a number rather than a promise. Gold and
// study time stay — those are what you are being asked to hand over (§1).
function tuitionView(?array $offer): ?array
{
    if ($offer === null) {
        return null;
    }
    unset($offer['gain'], $offer['current']);
    return $offer;
}

// Whether a promoted individual is on speaking terms — their people at Neutral
// or better, weighted so this specific person's opinion dominates the blend.
function willTeach(int $playerId, Npc $npc, array $row): bool
{
    $rel = relationRepo()->effective(
        $playerId,
        $npc->race(),
        $row['province_id'] !== null ? (int)$row['province_id'] : null,
        $row['site_id'] !== null ? (int)$row['site_id'] : null,
        (int)$row['id'],
    );
    return $rel->stageIndex() >= Relationship::STAGE_NEUTRAL;
}

// POST /api/training/start {npc_id} — pay a tutor and begin the lesson.
function handleTrainingStart(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    $charId = ensureCharacter($pid, (string)$player['username']);

    $sid = settlementRepo()->homeId($pid);
    if ($sid !== null) {
        npcRepo()->ensureVillage($pid, $sid);
    }

    $npc = npcRepo()->find((int)(body()['npc_id'] ?? 0), $pid);
    if (!$npc) {
        json(404, ['error' => 'No such NPC.']);
    }

    // Re-derive the offer server-side; never trust what the client thought the
    // lesson would cost or teach.
    $skills = loadCharacter($charId)['skills'];
    $offer  = tuitionOffer($pid, $charId, $npc, $skills);
    if (!$offer) {
        json(400, ['error' => 'They teach nothing.']);
    }
    if (!$offer['can']) {
        json(400, ['error' => $offer['reason']]);
    }
    if (!settlementRepo()->spendGold($pid, $offer['price'])) {
        json(400, ['error' => 'You cannot afford the fee.']);
    }

    characterRepo()->startTraining($charId, $offer['skill'], $offer['gain'], $offer['seconds']);

    json(201, [
        'training'  => tuitionView($offer),
        'character' => loadCharacter($charId),
    ]);
}

// POST /api/training/cancel — give up the lesson and get your life back. What
// you sat through stays with you; the fee does not come back.
function handleTrainingCancel(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], (string)$player['username']);

    $result = characterRepo()->cancelTraining($charId);
    if ($result === null) {
        json(400, ['error' => 'You are not studying anything.']);
    }

    // Say what they came away with the way everything else says it: as what
    // they can now make out, not as a number of points.
    $char  = loadCharacter($charId);
    $value = (int)($char['skills'][$result['skill']] ?? 0);

    json(200, [
        'learned'   => $result['learned'] > 0,
        'sense'     => Language::sense($value),
        'character' => $char,
    ]);
}
