<?php
declare(strict_types=1);

// Village NPCs & quests — the first slice of the "understanding" RPG layer.
// NPCs are the unified `npcs` table (village residents now, promoted monster
// individuals later). Quests are procedural templates instantiated per player.

// Resident NPCs seeded into a new player's home village. profession is the key;
// only some professions offer quests (see QUEST_OFFERS). Others are present but
// idle for now (shop / heal / train come later).
const VILLAGE_NPCS = [
    ['profession' => 'elder',    'name' => 'Elder Maroun',   'race' => 'human'],
    ['profession' => 'merchant', 'name' => 'Merchant Dessa', 'race' => 'human'],
    ['profession' => 'healer',   'name' => 'Healer Orin',    'race' => 'human'],
    ['profession' => 'scholar',  'name' => 'Scholar Yves',   'race' => 'human'],
];

// Quest templates, keyed. Only the elder's "clear the goblins" exists so far.
// A giver offers a template if its profession matches and the player has no
// active/turned-in copy already.
const QUEST_TEMPLATES = [
    'clear_goblins' => [
        'giver'        => 'elder',
        'title'        => 'Cull the Goblin Raiders',
        'objective'    => 'kill',
        'target_race'  => 'goblin',
        'target_count' => 5,
        'reward_gold'  => 120,
        'reward_rep'   => 10,
        'blurb'        => 'Goblins have been raiding our supplies. Slay five of them and bring proof.',
    ],
];

// The player's home settlement id (the village). Created at registration.
function homeSettlementId(int $playerId): ?int
{
    $stmt = db()->prepare('SELECT id FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$playerId]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Ensure the home village is populated with its resident NPCs. Idempotent.
function ensureVillage(int $playerId): void
{
    $settlementId = homeSettlementId($playerId);
    if ($settlementId === null) {
        return;
    }
    $db  = db();
    $chk = $db->prepare('SELECT COUNT(*) FROM npcs WHERE player_id = ? AND settlement_id = ?');
    $chk->execute([$playerId, $settlementId]);
    if ((int)$chk->fetchColumn() > 0) {
        return;
    }
    $ins = $db->prepare(
        'INSERT INTO npcs (player_id, race, profession, name, settlement_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (VILLAGE_NPCS as $n) {
        $ins->execute([$playerId, $n['race'], $n['profession'], $n['name'], $settlementId]);
    }
}

// Which quest template (if any) this NPC will offer right now: matches the
// giver profession and the player doesn't already have that quest.
function questOfferFor(int $playerId, array $npc): ?array
{
    foreach (QUEST_TEMPLATES as $key => $t) {
        if ($t['giver'] !== $npc['profession']) {
            continue;
        }
        $chk = db()->prepare('SELECT COUNT(*) FROM player_quests WHERE player_id = ? AND template_key = ?');
        $chk->execute([$playerId, $key]);
        if ((int)$chk->fetchColumn() === 0) {
            return ['key' => $key] + $t;
        }
    }
    return null;
}

function npcPayload(int $playerId, array $n): array
{
    $offer = questOfferFor($playerId, $n);
    return [
        'id'         => (int)$n['id'],
        'name'       => $n['name'],
        'race'       => $n['race'],
        'profession' => $n['profession'],
        'offer'      => $offer ? ['key' => $offer['key'], 'title' => $offer['title'], 'blurb' => $offer['blurb']] : null,
    ];
}

// GET /api/npcs — resident NPCs of the home village + the village reputation.
function handleNpcs(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    ensureVillage($pid);

    $sid = homeSettlementId($pid);
    $stmt = db()->prepare('SELECT * FROM npcs WHERE player_id = ? AND settlement_id = ? ORDER BY id');
    $stmt->execute([$pid, $sid]);
    $npcs = array_map(fn(array $n) => npcPayload($pid, $n), $stmt->fetchAll());

    $rep = db()->prepare('SELECT reputation FROM settlements WHERE id = ?');
    $rep->execute([$sid]);

    json(200, [
        'npcs'       => $npcs,
        'reputation' => (int)$rep->fetchColumn(),
    ]);
}

function questPayload(array $q): array
{
    return [
        'id'           => (int)$q['id'],
        'title'        => $q['title'],
        'objective'    => $q['objective'],
        'target_race'  => $q['target_race'],
        'target_count' => (int)$q['target_count'],
        'progress'     => (int)$q['progress'],
        'reward_gold'  => (int)$q['reward_gold'],
        'reward_rep'   => (int)$q['reward_rep'],
        'state'        => $q['state'],
    ];
}

// GET /api/quests — the player's active/done quests (turned-in ones are hidden).
function handleQuests(): void
{
    $player = requirePlayer();
    $stmt = db()->prepare(
        "SELECT * FROM player_quests WHERE player_id = ? AND state <> 'turned_in' ORDER BY id"
    );
    $stmt->execute([(int)$player['id']]);
    json(200, ['quests' => array_map('questPayload', $stmt->fetchAll())]);
}

// POST /api/quests/accept {npc_id} — the named NPC hands over their quest.
function handleQuestAccept(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    ensureVillage($pid);

    $npcId = (int)(body()['npc_id'] ?? 0);
    $stmt  = db()->prepare('SELECT * FROM npcs WHERE id = ? AND player_id = ?');
    $stmt->execute([$npcId, $pid]);
    $npc = $stmt->fetch();
    if (!$npc) {
        json(404, ['error' => 'No such NPC.']);
    }

    $offer = questOfferFor($pid, $npc);
    if (!$offer) {
        json(400, ['error' => 'They have nothing for you.']);
    }

    db()->prepare(
        'INSERT INTO player_quests
            (player_id, giver_npc_id, template_key, title, objective, target_race, target_count, reward_gold, reward_rep)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([
        $pid, $npcId, $offer['key'], $offer['title'], $offer['objective'],
        $offer['target_race'], $offer['target_count'], $offer['reward_gold'], $offer['reward_rep'],
    ]);

    $qid = (int)db()->lastInsertId();
    $q   = db()->prepare('SELECT * FROM player_quests WHERE id = ?');
    $q->execute([$qid]);
    json(201, ['quest' => questPayload($q->fetch())]);
}

// POST /api/quests/turn-in {quest_id} — claim rewards for a completed quest.
function handleQuestTurnIn(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];

    $qid  = (int)(body()['quest_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM player_quests WHERE id = ? AND player_id = ?');
    $stmt->execute([$qid, $pid]);
    $q = $stmt->fetch();
    if (!$q) {
        json(404, ['error' => 'No such quest.']);
    }
    if ($q['state'] !== 'done') {
        json(400, ['error' => 'That quest is not complete yet.']);
    }

    // Gold into the home settlement; reputation onto the village.
    addGold($player, (int)$q['reward_gold']);
    if ((int)$q['reward_rep'] !== 0) {
        db()->prepare('UPDATE settlements SET reputation = reputation + ? WHERE player_id = ? ORDER BY id LIMIT 1')
            ->execute([(int)$q['reward_rep'], $pid]);
    }
    db()->prepare("UPDATE player_quests SET state = 'turned_in' WHERE id = ?")->execute([$qid]);

    json(200, [
        'reward_gold' => (int)$q['reward_gold'],
        'reward_rep'  => (int)$q['reward_rep'],
    ]);
}

// Advance the player's active kill-quests after a winning fight against `race`.
// Called from resolveFight(). Flips a quest to 'done' when its target is met.
function advanceKillQuests(int $playerId, string $race): void
{
    if ($race === '') {
        return;
    }
    $db   = db();
    $stmt = $db->prepare(
        "SELECT * FROM player_quests
         WHERE player_id = ? AND state = 'active' AND objective = 'kill' AND target_race = ?"
    );
    $stmt->execute([$playerId, $race]);
    foreach ($stmt->fetchAll() as $q) {
        $progress = (int)$q['progress'] + 1;
        $done     = $progress >= (int)$q['target_count'];
        $db->prepare(
            "UPDATE player_quests SET progress = ?, state = ? WHERE id = ?"
        )->execute([$progress, $done ? 'done' : 'active', (int)$q['id']]);
    }
}
