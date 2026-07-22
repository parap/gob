<?php
declare(strict_types=1);

// Relative chance of an item of each rarity dropping. Rarer = lower weight.
const RARITY_WEIGHTS = [
    'common'   => 100,
    'uncommon' => 30,
    'rare'     => 8,
    'epic'     => 2,
];

// How long the hero must rest between loot searches.
const LOOT_COOLDOWN_SECONDS = 20;

// Brief view of an item definition (no owned-instance context).
function itemDefBrief(array $i): array
{
    $bonuses = [];
    foreach (['str', 'dex', 'con', 'int', 'wis', 'cha', 'hp', 'mana', 'courage',
              'defense', 'protection', 'attack', 'penetration', 'perception'] as $k) {
        $v = (int)$i["bonus_$k"];
        if ($v !== 0) {
            $bonuses[$k] = $v;
        }
    }
    return [
        'item_id'   => (int)$i['id'],
        'name'      => $i['name'],
        'slot_type' => $i['slot_type'],
        'rarity'    => $i['rarity'],
        'kind'      => $i['kind'],
        'heal'      => (int)$i['heal_hp'],
        'bonuses'   => $bonuses,
    ];
}

// Pick one item, weighted by rarity.
function rollLoot(array $items): array
{
    $total = 0;
    foreach ($items as $it) {
        $total += RARITY_WEIGHTS[$it['rarity']] ?? 10;
    }
    $r   = random_int(1, $total);
    $acc = 0;
    foreach ($items as $it) {
        $acc += RARITY_WEIGHTS[$it['rarity']] ?? 10;
        if ($r <= $acc) {
            return $it;
        }
    }
    return $items[array_key_last($items)]; // unreachable, keeps the type happy
}

function handleLootSearch(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $db     = db();

    // Enforce the cooldown.
    $stmt = $db->prepare('SELECT last_loot_at FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $elapsed = time() - strtotime($last);
        if ($elapsed < LOOT_COOLDOWN_SECONDS) {
            json(429, [
                'error'       => 'Your hero is still resting.',
                'retry_after' => LOOT_COOLDOWN_SECONDS - $elapsed,
            ]);
        }
    }

    $items = $db->query('SELECT * FROM items')->fetchAll();
    if (!$items) {
        json(500, ['error' => 'No items defined.']);
    }

    $picked = rollLoot($items);
    grantItem($charId, (int)$picked['id']);
    $db->prepare('UPDATE characters SET last_loot_at = NOW() WHERE id = ?')->execute([$charId]);

    json(200, [
        'found'            => itemDefBrief($picked),
        'cooldown_seconds' => LOOT_COOLDOWN_SECONDS,
    ]);
}
