<?php
declare(strict_types=1);

const EXPLORE_COOLDOWN_SECONDS = 15;

// All locations a player has discovered, with progress and the next enemy.
function listPlayerLocations(int $playerId): array
{
    $stmt = db()->prepare(
        'SELECT pl.id, pl.location_id, pl.progress, pl.state,
                l.type, l.name, l.description, l.level,
                l.bonus_gold_rate, l.bonus_wood_rate, l.bonus_stone_rate, l.bonus_regen, l.reward_item_id,
                (SELECT COUNT(*) FROM location_stages s WHERE s.location_id = l.id) AS total_stages,
                (SELECT m.name FROM location_stages s JOIN monsters m ON m.id = s.monster_id
                 WHERE s.location_id = l.id AND s.stage_no = pl.progress + 1) AS next_monster
         FROM player_locations pl
         JOIN locations l ON l.id = pl.location_id
         WHERE pl.player_id = ?
         ORDER BY l.level, pl.id'
    );
    $stmt->execute([$playerId]);

    return array_map(fn(array $r) => [
        'id'           => (int)$r['id'],
        'type'         => $r['type'],
        'name'         => $r['name'],
        'description'  => $r['description'],
        'level'        => (int)$r['level'],
        'progress'     => (int)$r['progress'],
        'total_stages' => (int)$r['total_stages'],
        'state'        => $r['state'],
        'next_monster' => $r['next_monster'],
        'reward'       => [
            'gold_rate'  => (int)$r['bonus_gold_rate'],
            'wood_rate'  => (int)$r['bonus_wood_rate'],
            'stone_rate' => (int)$r['bonus_stone_rate'],
            'regen'      => (int)$r['bonus_regen'],
            'item_id'    => $r['reward_item_id'] !== null ? (int)$r['reward_item_id'] : null,
        ],
    ], $stmt->fetchAll());
}

function handleLocations(): void
{
    $player = requirePlayer();
    json(200, listPlayerLocations((int)$player['id']));
}

function handleExplore(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $db     = db();

    // Cooldown.
    $stmt = $db->prepare('SELECT last_explore_at FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $elapsed = time() - strtotime($last);
        if ($elapsed < EXPLORE_COOLDOWN_SECONDS) {
            json(429, [
                'error'       => 'You must rest before exploring again.',
                'retry_after' => EXPLORE_COOLDOWN_SECONDS - $elapsed,
            ]);
        }
    }
    $db->prepare('UPDATE characters SET last_explore_at = NOW() WHERE id = ?')->execute([$charId]);

    // What the hero can currently perceive gates what they can find.
    $perception = loadCharacter($charId)['substats_effective']['perception'];

    // Discover a random undiscovered location within the hero's perception.
    $stmt = $db->prepare(
        'SELECT * FROM locations
         WHERE id NOT IN (SELECT location_id FROM player_locations WHERE player_id = ?)
           AND min_perception <= ?
         ORDER BY RAND() LIMIT 1'
    );
    $stmt->execute([$player['id'], $perception]);
    $loc = $stmt->fetch();

    if (!$loc) {
        // Say nothing about what might be out there — don't leak undiscovered
        // locations (whether it's perception-gated or simply nothing left).
        json(200, [
            'found'            => null,
            'message'          => 'You explored the area but found nothing.',
            'cooldown_seconds' => EXPLORE_COOLDOWN_SECONDS,
            'locations'        => listPlayerLocations((int)$player['id']),
        ]);
    }

    $db->prepare('INSERT INTO player_locations (player_id, location_id) VALUES (?, ?)')
       ->execute([$player['id'], $loc['id']]);

    json(200, [
        'found'            => ['type' => $loc['type'], 'name' => $loc['name'], 'level' => (int)$loc['level']],
        'cooldown_seconds' => EXPLORE_COOLDOWN_SECONDS,
        'locations'        => listPlayerLocations((int)$player['id']),
    ]);
}

// Apply a location's completion rewards. Sites permanently raise the primary
// settlement's production rates; both types grant the reward item.
function applyLocationCompletion(array $player, int $charId, array $loc): array
{
    $db      = db();
    $summary = ['rate' => null, 'item' => null];

    if ($loc['type'] === 'site') {
        $hasRate = (int)$loc['bonus_gold_rate'] || (int)$loc['bonus_wood_rate'] || (int)$loc['bonus_stone_rate'];
        if ($hasRate) {
            $stmt = $db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
            $stmt->execute([$player['id']]);
            if ($settlement = $stmt->fetch()) {
                // Settle production at the OLD rate before bumping it.
                tickSettlement($settlement);
                $db->prepare(
                    'UPDATE settlements SET
                        rate_gold_per_hour  = rate_gold_per_hour  + ?,
                        rate_wood_per_hour  = rate_wood_per_hour  + ?,
                        rate_stone_per_hour = rate_stone_per_hour + ?
                     WHERE id = ?'
                )->execute([
                    (int)$loc['bonus_gold_rate'], (int)$loc['bonus_wood_rate'],
                    (int)$loc['bonus_stone_rate'], $settlement['id'],
                ]);
                $summary['rate'] = [
                    'gold'  => (int)$loc['bonus_gold_rate'],
                    'wood'  => (int)$loc['bonus_wood_rate'],
                    'stone' => (int)$loc['bonus_stone_rate'],
                ];
            }
        }

        // Ongoing regen reward: settle current regen, then raise the rate.
        if ((int)$loc['bonus_regen'] > 0) {
            tickCharacterRegen($charId);
            $db->prepare('UPDATE characters SET regen_bonus = regen_bonus + ? WHERE id = ?')
               ->execute([(int)$loc['bonus_regen'], $charId]);
            $summary['regen'] = (int)$loc['bonus_regen'];
        }
    }

    if ($loc['reward_item_id'] !== null) {
        grantItem($charId, (int)$loc['reward_item_id']);
        $nm = $db->prepare('SELECT name FROM items WHERE id = ?');
        $nm->execute([(int)$loc['reward_item_id']]);
        $summary['item'] = $nm->fetchColumn();
    }

    return $summary;
}

function handleAdvance(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $db     = db();

    $plId = (int)(body()['player_location_id'] ?? 0);

    $stmt = $db->prepare(
        'SELECT pl.*, l.type, l.name, l.reward_item_id,
                l.bonus_gold_rate, l.bonus_wood_rate, l.bonus_stone_rate, l.bonus_regen
         FROM player_locations pl JOIN locations l ON l.id = pl.location_id
         WHERE pl.id = ? AND pl.player_id = ?'
    );
    $stmt->execute([$plId, $player['id']]);
    $loc = $stmt->fetch();
    if (!$loc) {
        json(404, ['error' => 'Location not found.']);
    }
    if ($loc['state'] === 'cleared') {
        json(400, ['error' => 'This location is already cleared.']);
    }

    $total = (int)$db->query(
        'SELECT COUNT(*) FROM location_stages WHERE location_id = ' . (int)$loc['location_id']
    )->fetchColumn();

    $stageNo = (int)$loc['progress'] + 1;
    $stmt = $db->prepare(
        'SELECT m.* FROM location_stages s JOIN monsters m ON m.id = s.monster_id
         WHERE s.location_id = ? AND s.stage_no = ?'
    );
    $stmt->execute([$loc['location_id'], $stageNo]);
    $monster = $stmt->fetch();
    if (!$monster) {
        json(500, ['error' => 'Stage data missing.']);
    }

    $combat     = resolveFight($player, $charId, $monster);
    $completion = null;

    if ($combat['outcome'] === 'win') {
        if ($stageNo >= $total) {
            $db->prepare('UPDATE player_locations SET progress = ?, state = "cleared", cleared_at = NOW() WHERE id = ?')
               ->execute([$stageNo, $plId]);
            $completion = applyLocationCompletion($player, $charId, $loc);
        } else {
            $db->prepare('UPDATE player_locations SET progress = ? WHERE id = ?')->execute([$stageNo, $plId]);
        }
    }

    json(200, [
        'combat'        => $combat,
        'stage'         => $stageNo,
        'total_stages'  => $total,
        'cleared'       => $completion !== null,
        'completion'    => $completion,
        'location_name' => $loc['name'],
        'locations'     => listPlayerLocations((int)$player['id']),
        'character'     => loadCharacter($charId),
    ]);
}
