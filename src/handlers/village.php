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
        'proof_item_id' => 19,   // Goblin Ear — collected on each kill, consumed at turn-in
        'proof_count'   => 5,
        'blurb'        => 'Goblins have been raiding our supplies. Slay five of them and bring their ears as proof.',
        'dialog'       => "Ah — you've the look of someone who's handled a blade before. Good. We could use it.\n\n"
            . "For weeks now the goblins from the old caves have been creeping down after dark, carrying off our grain and tools. Vicious little things — the watch can't be everywhere at once.\n\n"
            . "Thin them out. Five should be enough to teach the rest to keep their distance. Bring back proof of the deed and there'll be coin in it for you — and the village won't forget the favour.",
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
        'offer'      => $offer ? [
            'key'    => $offer['key'],
            'title'  => $offer['title'],
            'blurb'  => $offer['blurb'],
            'dialog' => $offer['dialog'] ?? $offer['blurb'],
        ] : null,
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

// How many of the given item the player's character currently owns.
function ownedItemCount(int $playerId, int $itemId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM character_items ci
         JOIN characters c ON c.id = ci.character_id
         WHERE c.player_id = ? AND ci.item_id = ?'
    );
    $stmt->execute([$playerId, $itemId]);
    return (int)$stmt->fetchColumn();
}

// The proof requirement for a quest, with how many the player currently holds
// (null when the quest needs no tangible proof).
function questProof(int $playerId, array $q): ?array
{
    $t = QUEST_TEMPLATES[$q['template_key']] ?? null;
    if (!$t || empty($t['proof_item_id'])) {
        return null;
    }
    $nm = db()->prepare('SELECT name FROM items WHERE id = ?');
    $nm->execute([(int)$t['proof_item_id']]);
    return [
        'item'  => $nm->fetchColumn(),
        'need'  => (int)$t['proof_count'],
        'have'  => ownedItemCount($playerId, (int)$t['proof_item_id']),
    ];
}

function questPayload(int $playerId, array $q): array
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
        'proof'        => questProof($playerId, $q),
    ];
}

// GET /api/quests — the player's active/done quests (turned-in ones are hidden).
function handleQuests(): void
{
    $player = requirePlayer();
    $stmt = db()->prepare(
        "SELECT * FROM player_quests WHERE player_id = ? AND state <> 'turned_in' ORDER BY id"
    );
    $pid = (int)$player['id'];
    $stmt->execute([$pid]);
    json(200, ['quests' => array_map(fn(array $q) => questPayload($pid, $q), $stmt->fetchAll())]);
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
    $qid = (int)db()->lastInsertId();   // capture BEFORE any other INSERT below

    // Give the goblin cull a concrete place to happen: a guaranteed, already-
    // revealed Goblin Cave in the home province (kills there tick the quest).
    if ($offer['key'] === 'clear_goblins') {
        $charId = ensureCharacter($pid, (string)$player['username']);
        spawnGoblinCave($pid, $charId);
    }

    $q = db()->prepare('SELECT * FROM player_quests WHERE id = ?');
    $q->execute([$qid]);
    json(201, ['quest' => questPayload($pid, $q->fetch())]);
}

// Ensure the player has a revealed "Goblin Cave" site in their home province.
// Reuses the existing province-site/delve system; five goblin stages, so
// clearing it satisfies the "slay 5" quest. Idempotent (one cave per player).
function spawnGoblinCave(int $playerId, int $charId): void
{
    $homeId = ensureHomeProvince($playerId, $charId);
    $db = db();

    $chk = $db->prepare("SELECT id FROM province_sites WHERE player_id = ? AND name = 'Goblin Cave' LIMIT 1");
    $chk->execute([$playerId]);
    if ($chk->fetchColumn()) {
        return;
    }

    $stages = [1, 1, 1, 1, 5]; // four Goblin Scouts + a Goblin Warlord (all race 'goblin')
    $db->prepare(
        'INSERT INTO province_sites
            (province_id, player_id, type, name, position, concealment, state, progress,
             reward_gold, bonus_gold_rate, bonus_wood_rate, bonus_stone_rate, bonus_regen,
             reward_item_id, road_terrain, stages_json, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $homeId, $playerId, 'minor', 'Goblin Cave', 50.0, 0, 'found', 0,
        40, 0, 0, 0, 0, null, null, json_encode($stages),
        'The old caves the goblins raid from. Elder Maroun wants them cleared.',
    ]);
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

    // Require and consume the tangible proof, if the template asks for one.
    $t = QUEST_TEMPLATES[$q['template_key']] ?? null;
    if ($t && !empty($t['proof_item_id'])) {
        $itemId = (int)$t['proof_item_id'];
        $need   = (int)$t['proof_count'];
        if (ownedItemCount($pid, $itemId) < $need) {
            $nm = db()->prepare('SELECT name FROM items WHERE id = ?');
            $nm->execute([$itemId]);
            $itemName = (string)$nm->fetchColumn();
            json(400, ['error' => "You lack the proof — bring {$need} {$itemName}(s)."]);
        }
        $charId = ensureCharacter($pid, (string)$player['username']);
        // $need is a trusted int from the template, so it's safe to inline (avoids
        // driver-specific issues binding a parameter in LIMIT).
        db()->prepare(
            'DELETE FROM character_items WHERE character_id = ? AND item_id = ? ORDER BY id LIMIT ' . (int)$need
        )->execute([$charId, $itemId]);
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
