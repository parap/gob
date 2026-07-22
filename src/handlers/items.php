<?php
declare(strict_types=1);

// The specific equipment slots an item of a given slot_type may occupy.
// Rings and bracelets each have two numbered slots; everything else is 1:1.
function slotsForType(string $type): array
{
    return match ($type) {
        'ring'     => ['ring_1', 'ring_2'],
        'bracelet' => ['bracelet_1', 'bracelet_2'],
        default    => [$type],
    };
}

// Shape a joined character_items + items row into API detail.
function itemDetail(array $r): array
{
    $bonuses = [];
    foreach (['str', 'dex', 'con', 'int', 'wis', 'cha', 'hp', 'mana', 'courage',
              'defense', 'protection', 'attack', 'penetration'] as $k) {
        $v = (int)$r["bonus_$k"];
        if ($v !== 0) {
            $bonuses[$k] = $v;
        }
    }
    return [
        'char_item_id'  => (int)$r['ci_id'],
        'item_id'       => (int)$r['item_id'],
        'name'          => $r['name'],
        'slot_type'     => $r['slot_type'],
        'rarity'        => $r['rarity'],
        'weapon_skill'  => $r['weapon_skill'],
        'equipped_slot' => $r['equipped_slot'],
        'kind'          => $r['kind'],
        'heal'          => (int)$r['heal_hp'],
        'bonuses'       => $bonuses,
    ];
}

// All items a character owns (equipped and backpack), as detail rows.
function ownedItems(int $charId): array
{
    $stmt = db()->prepare(
        'SELECT ci.id AS ci_id, ci.item_id, ci.equipped_slot,
                i.name, i.slot_type, i.rarity, i.weapon_skill,
                i.bonus_str, i.bonus_dex, i.bonus_con, i.bonus_int, i.bonus_wis, i.bonus_cha,
                i.bonus_hp, i.bonus_mana, i.bonus_courage,
                i.bonus_defense, i.bonus_protection, i.bonus_attack, i.bonus_penetration,
                i.kind, i.heal_hp
         FROM character_items ci
         JOIN items i ON i.id = ci.item_id
         WHERE ci.character_id = ?
         ORDER BY i.name'
    );
    $stmt->execute([$charId]);
    return array_map('itemDetail', $stmt->fetchAll());
}

// Give a character an item instance (goes to the backpack, unequipped).
function grantItem(int $charId, int $itemId): void
{
    db()->prepare(
        'INSERT INTO character_items (character_id, item_id) VALUES (?, ?)'
    )->execute([$charId, $itemId]);
}

function handleEquip(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $b          = body();
    $charItemId = (int)($b['char_item_id'] ?? 0);
    $wantedSlot = isset($b['slot']) ? (string)$b['slot'] : null;

    $db = db();

    // The instance must belong to this character.
    $stmt = $db->prepare(
        'SELECT ci.id, i.slot_type, i.kind
         FROM character_items ci JOIN items i ON i.id = ci.item_id
         WHERE ci.id = ? AND ci.character_id = ?'
    );
    $stmt->execute([$charItemId, $charId]);
    $row = $stmt->fetch();
    if (!$row) {
        json(404, ['error' => 'Item not found.']);
    }
    if ($row['kind'] === 'consumable') {
        json(400, ['error' => 'That item cannot be equipped — use it instead.']);
    }

    $allowed = slotsForType($row['slot_type']);

    // Pick a slot: honour the requested one if valid, else first free slot,
    // else fall back to the first allowed slot (replacing what's there).
    if ($wantedSlot !== null) {
        if (!in_array($wantedSlot, $allowed, true)) {
            json(400, ['error' => 'That item does not fit that slot.']);
        }
        $slot = $wantedSlot;
    } else {
        $slot = firstFreeSlot($charId, $allowed) ?? $allowed[0];
    }

    // Transaction: clear the target slot, then move this item into it.
    $db->beginTransaction();
    $db->prepare('UPDATE character_items SET equipped_slot = NULL WHERE character_id = ? AND equipped_slot = ?')
       ->execute([$charId, $slot]);
    $db->prepare('UPDATE character_items SET equipped_slot = ? WHERE id = ?')
       ->execute([$slot, $charItemId]);
    $db->commit();

    handleMyCharacter();
}

function firstFreeSlot(int $charId, array $allowed): ?string
{
    $stmt = db()->prepare(
        'SELECT equipped_slot FROM character_items
         WHERE character_id = ? AND equipped_slot IS NOT NULL'
    );
    $stmt->execute([$charId]);
    $taken = array_column($stmt->fetchAll(), 'equipped_slot');
    foreach ($allowed as $slot) {
        if (!in_array($slot, $taken, true)) {
            return $slot;
        }
    }
    return null;
}

function handleUseItem(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $db     = db();

    $charItemId = (int)(body()['char_item_id'] ?? 0);
    $stmt = $db->prepare(
        'SELECT ci.id, i.kind, i.heal_hp, i.name
         FROM character_items ci JOIN items i ON i.id = ci.item_id
         WHERE ci.id = ? AND ci.character_id = ?'
    );
    $stmt->execute([$charItemId, $charId]);
    $row = $stmt->fetch();
    if (!$row) {
        json(404, ['error' => 'Item not found.']);
    }
    if ($row['kind'] !== 'consumable') {
        json(400, ['error' => 'That item cannot be used.']);
    }

    tickCharacterRegen($charId); // settle passive regen before healing

    $ch = $db->prepare('SELECT hp, hp_max FROM characters WHERE id = ?');
    $ch->execute([$charId]);
    $c = $ch->fetch();

    $effMax = (int)$c['hp_max'] + equippedHpBonus($charId);
    $newHp  = min($effMax, (int)$c['hp'] + (int)$row['heal_hp']);
    $healed = $newHp - (int)$c['hp'];

    $db->prepare('UPDATE characters SET hp = ? WHERE id = ?')->execute([$newHp, $charId]);
    $db->prepare('DELETE FROM character_items WHERE id = ?')->execute([$charItemId]);

    json(200, [
        'healed'    => $healed,
        'name'      => $row['name'],
        'character' => loadCharacter($charId),
    ]);
}

function handleUnequip(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $slot = (string)(body()['slot'] ?? '');
    db()->prepare('UPDATE character_items SET equipped_slot = NULL WHERE character_id = ? AND equipped_slot = ?')
        ->execute([$charId, $slot]);

    handleMyCharacter();
}
