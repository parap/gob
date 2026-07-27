<?php
declare(strict_types=1);

use Gob\Domain\Language;
use Gob\Domain\Npc;
use Gob\Domain\Tutor;

// Tuition: paying a tutor to teach you a tongue (future_implementation.md §5).
//
// Training is a timed job with a single slot — you pay up front and the skill
// lands when the lesson finishes. Nothing here is advertised: the scholar
// mentions no languages and the UI makes no suggestion. A player who never
// asks never finds out, which is the point (§1).

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

    $reason = null;
    if ($gain <= 0) {
        $reason = 'You already speak it better than they do.';
    } elseif ($busy) {
        $reason = 'You are already studying something else.';
    } elseif ($gold < $price) {
        $reason = "You cannot afford the fee ({$price}g).";
    }

    return [
        'skill'       => $skill,
        'label'       => Language::label($skill),
        'tongue'      => $teaches,
        'current'     => $current,
        'fluency'     => Language::fluency($current),
        'gain'        => $gain,
        'price'       => $price,
        'seconds'     => Tutor::seconds($gain),
        'can'         => $reason === null,
        'reason'      => $reason,
    ];
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
        'training'  => $offer,
        'character' => loadCharacter($charId),
    ]);
}
