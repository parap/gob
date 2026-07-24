<?php
declare(strict_types=1);

use Gob\Domain\Npc;
use Gob\Domain\Quest;
use Gob\Repository\NpcRepository;
use Gob\Repository\QuestRepository;

// Village NPCs & quests — now backed by Gob\Domain\{Npc,Quest} and
// Gob\Repository\{NpcRepository,QuestRepository}. These are the route handlers
// plus the two things still procedural: spawnGoblinCave (world-gen) and the
// advanceKillQuests() wrapper (called from combat's resolveFight).

function npcRepo(): NpcRepository
{
    return \Gob\Repositories::get(NpcRepository::class);
}

function questRepo(): QuestRepository
{
    return \Gob\Repositories::get(QuestRepository::class);
}

// GET /api/npcs — resident NPCs of the home village + the village reputation.
function handleNpcs(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];

    $sid = settlementRepo()->homeId($pid);
    $npcs = [];
    if ($sid !== null) {
        npcRepo()->ensureVillage($pid, $sid);
        $qrepo = questRepo();
        $npcs = array_map(function (array $n) use ($pid, $qrepo) {
            $offer = $qrepo->offerFor($pid, $n);
            return (new Npc($n))->toArray($offer ? Quest::offerView($offer['key']) : null);
        }, npcRepo()->residents($pid, $sid));
    }

    json(200, [
        'npcs'       => $npcs,
        'reputation' => settlementRepo()->reputation($pid),
    ]);
}

// GET /api/quests — the player's active/done quests (turned-in ones hidden).
function handleQuests(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    $qrepo  = questRepo();

    $out = array_map(
        fn(array $q) => Quest::payload($q, $qrepo->proofFor($pid, $q)),
        $qrepo->activeForPlayer($pid)
    );
    json(200, ['quests' => $out]);
}

// POST /api/quests/accept {npc_id} — the named NPC hands over their quest.
function handleQuestAccept(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];

    $sid = settlementRepo()->homeId($pid);
    if ($sid !== null) {
        npcRepo()->ensureVillage($pid, $sid);
    }

    $npcId = (int)(body()['npc_id'] ?? 0);
    $npc   = npcRepo()->find($npcId, $pid);
    if (!$npc) {
        json(404, ['error' => 'No such NPC.']);
    }

    $qrepo = questRepo();
    $offer = $qrepo->offerFor($pid, $npc);
    if (!$offer) {
        json(400, ['error' => 'They have nothing for you.']);
    }

    $qid = $qrepo->create($pid, $npcId, $offer);

    // Give the goblin cull a concrete place to happen: a guaranteed, already-
    // revealed Goblin Cave in the home province (kills there tick the quest).
    if ($offer['key'] === 'clear_goblins') {
        $charId = ensureCharacter($pid, (string)$player['username']);
        spawnGoblinCave($pid, $charId);
    }

    $q = $qrepo->find($qid, $pid);
    json(201, ['quest' => Quest::payload($q, $qrepo->proofFor($pid, $q))]);
}

// POST /api/quests/turn-in {quest_id} — claim rewards for a completed quest.
function handleQuestTurnIn(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    $qrepo  = questRepo();

    $qid = (int)(body()['quest_id'] ?? 0);
    $q   = $qrepo->find($qid, $pid);
    if (!$q) {
        json(404, ['error' => 'No such quest.']);
    }
    if ($q['state'] !== 'done') {
        json(400, ['error' => 'That quest is not complete yet.']);
    }

    // Require and consume the tangible proof, if the template asks for one.
    $t = Quest::template($q['template_key']);
    if ($t && !empty($t['proof_item_id'])) {
        $items  = itemRepo();
        $itemId = (int)$t['proof_item_id'];
        $need   = (int)$t['proof_count'];
        if ($items->countForPlayer($pid, $itemId) < $need) {
            $itemName = $items->name($itemId);
            json(400, ['error' => "You lack the proof — bring {$need} {$itemName}(s)."]);
        }
        $charId = ensureCharacter($pid, (string)$player['username']);
        $items->consume($charId, $itemId, $need);
    }

    // Gold into the home settlement; reputation onto the village.
    addGold($player, (int)$q['reward_gold']);
    settlementRepo()->addReputation($pid, (int)$q['reward_rep']);
    $qrepo->markTurnedIn($qid);

    json(200, [
        'reward_gold' => (int)$q['reward_gold'],
        'reward_rep'  => (int)$q['reward_rep'],
    ]);
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

// Called from combat's resolveFight() on a winning fight. Delegating wrapper.
function advanceKillQuests(int $playerId, string $race): void
{
    questRepo()->advanceKills($playerId, $race);
}
