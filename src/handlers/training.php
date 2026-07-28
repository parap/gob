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
        'training'  => $offer,
        'character' => loadCharacter($charId),
    ]);
}
