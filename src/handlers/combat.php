<?php
declare(strict_types=1);

// Safety cap so a fight can never loop forever.
const MAX_COMBAT_ROUNDS = 60;

// Goblins drop an ear when slain — the tangible "proof" the elder's quest wants.
const GOBLIN_EAR_ITEM_ID = 19;

function handleMonsters(): void
{
    requirePlayer();
    $db   = db();
    $rows = $db->query('SELECT * FROM monsters ORDER BY level, id')->fetchAll();

    // Tags grouped by monster.
    $tags = [];
    foreach ($db->query('SELECT monster_id, tag FROM monster_tags ORDER BY tag')->fetchAll() as $t) {
        $tags[(int)$t['monster_id']][] = $t['tag'];
    }

    $out = array_map(fn(array $m) => [
        'id'          => (int)$m['id'],
        'name'        => $m['name'],
        'level'       => (int)$m['level'],
        'hp'          => (int)$m['hp'],
        'attack'      => (int)$m['attack'],
        'defense'     => (int)$m['defense'],
        'protection'  => (int)$m['protection'],
        'penetration' => (int)$m['penetration'],
        'reward_gold' => (int)$m['reward_gold'],
        'race'        => $m['race'] ?? 'unknown',
        'alignment'   => $m['alignment'] ?? 'neutral',
        'tags'        => $tags[(int)$m['id']] ?? [],
        'description' => $m['description'] ?? '',
    ], $rows);
    json(200, $out);
}

// One blow: attack power blunted by the target's defense, then reduced by
// protection (which penetration erodes), with a small ±15% swing.
function combatDamage(int $power, int $defDefense, int $defProtection, int $atkPenetration): int
{
    $afterDefense = max(0, $power - $defDefense);
    $soak         = max(0, $defProtection - $atkPenetration);
    $base         = max(1, $afterDefense - $soak);
    return max(1, (int)round($base * random_int(85, 115) / 100));
}

function handleAttack(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $monsterId = (int)(body()['monster_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM monsters WHERE id = ?');
    $stmt->execute([$monsterId]);
    $m = $stmt->fetch();
    if (!$m) {
        json(404, ['error' => 'Monster not found.']);
    }

    $res              = resolveFight($player, $charId, $m);
    $res['character'] = loadCharacter($charId);
    json(200, $res);
}

// Simulate a fight between the hero and a monster row, persist the hero's HP,
// grant win rewards (gold + skill training + monster loot), and return the
// result (outcome, rounds, log, hero_hp_after, rewards, monster). Reused by
// both the arena (handleAttack) and exploration (handleAdvance).
function resolveFight(array $player, int $charId, array $m): array
{
    $db = db();

    // Fresh hero numbers (regen already applied inside loadCharacter).
    $c = loadCharacter($charId);

    // Which skill the equipped weapon trains (unarmed if bare-handed).
    $weapon          = $c['equipment']['weapon'] ?? null;
    $weaponSkillName = $weapon['weapon_skill'] ?? 'unarmed';
    $weaponSkillVal  = $c['skills'][$weaponSkillName] ?? 0;
    $attackSkill     = $c['skills']['attack'] ?? 1;

    // Attack power = weapon/gear attack + combat skills + a little muscle.
    $heroPower = $c['substats_effective']['attack']
        + $attackSkill + $weaponSkillVal
        + intdiv($c['stats_effective']['str'], 2);
    $heroPen  = $c['substats_effective']['penetration'];
    $heroDef  = $c['substats_effective']['defense'];
    $heroProt = $c['substats_effective']['protection'];

    $heroHp = $c['vitals']['hp'];
    $monHp  = (int)$m['hp'];

    $log   = [];
    $round = 1;
    while ($monHp > 0 && $heroHp > 0 && $round <= MAX_COMBAT_ROUNDS) {
        $d = combatDamage($heroPower, (int)$m['defense'], (int)$m['protection'], $heroPen);
        $monHp -= $d;
        $log[] = ['round' => $round, 'actor' => 'hero', 'damage' => $d, 'target_hp' => max(0, $monHp)];
        if ($monHp <= 0) {
            break;
        }

        $d2 = combatDamage((int)$m['attack'], $heroDef, $heroProt, (int)$m['penetration']);
        $heroHp -= $d2;
        $log[] = ['round' => $round, 'actor' => 'monster', 'damage' => $d2, 'target_hp' => max(0, $heroHp)];
        $round++;
    }

    $win = $monHp <= 0 && $heroHp > 0;

    // Persist the hero's HP; a defeated hero is left knocked out at 1 HP.
    $finalHp = $heroHp > 0 ? $heroHp : 1;
    $db->prepare('UPDATE characters SET hp = ? WHERE id = ?')->execute([$finalHp, $charId]);

    $rewards = ['gold' => 0, 'skills' => [], 'items' => []];
    if ($win) {
        // Gold into the first settlement (clamped to its capacity).
        $sid = $db->prepare('SELECT id FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $sid->execute([$player['id']]);
        $settlementId = $sid->fetchColumn();
        if ($settlementId) {
            $gold = (int)$m['reward_gold'];
            $db->prepare('UPDATE settlements SET gold = LEAST(capacity_gold, gold + ?) WHERE id = ?')
               ->execute([$gold, $settlementId]);
            $rewards['gold'] = $gold;
        }

        // Train the skills that did the work (capped at 100).
        foreach (array_unique(['attack', $weaponSkillName]) as $sk) {
            $db->prepare('UPDATE character_skills SET value = LEAST(100, value + 1) WHERE character_id = ? AND skill = ?')
               ->execute([$charId, $sk]);
            $rewards['skills'][] = $sk;
        }

        // Roll for a loot drop.
        if ($m['loot_item_id'] !== null && (int)$m['loot_chance'] > 0
            && random_int(1, 100) <= (int)$m['loot_chance']) {
            grantItem($charId, (int)$m['loot_item_id']);
            $nm = $db->prepare('SELECT name FROM items WHERE id = ?');
            $nm->execute([(int)$m['loot_item_id']]);
            $rewards['items'][] = $nm->fetchColumn();
        }

        // Goblins yield an ear (proof). Guaranteed, on top of the normal loot roll.
        if (($m['race'] ?? '') === 'goblin') {
            grantItem($charId, GOBLIN_EAR_ITEM_ID);
            $rewards['items'][] = 'Goblin Ear';
        }

        // Advance any active kill-quests targeting this monster's race.
        advanceKillQuests((int)$player['id'], (string)($m['race'] ?? ''));
    }

    return [
        'outcome'       => $win ? 'win' : 'loss',
        'rounds'        => min($round, MAX_COMBAT_ROUNDS),
        'log'           => $log,
        'monster'       => ['id' => (int)$m['id'], 'name' => $m['name'], 'hp' => (int)$m['hp']],
        'hero_hp_after' => $finalHp,
        'rewards'       => $rewards,
    ];
}
