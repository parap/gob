<?php
declare(strict_types=1);

use Gob\Domain\Item;
use Gob\Repository\ItemRepository;

// Item logic now lives in Gob\Domain\Item and Gob\Repository\ItemRepository.
// ownedItems()/grantItem() stay as globals (many callers across the handlers);
// the rest is orchestrated here.

function itemRepo(): ItemRepository
{
    return \Gob\Repositories::get(ItemRepository::class);
}

// All items a character owns (equipped + backpack), as detail rows.
function ownedItems(int $charId): array
{
    return itemRepo()->owned($charId);
}

// Give a character an item instance (backpack, unequipped).
function grantItem(int $charId, int $itemId): void
{
    itemRepo()->grant($charId, $itemId);
}

function handleEquip(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $b          = body();
    $charItemId = (int)($b['char_item_id'] ?? 0);
    $wantedSlot = isset($b['slot']) ? (string)$b['slot'] : null;

    $repo = itemRepo();
    $row  = $repo->instance($charItemId, $charId);
    if (!$row) {
        json(404, ['error' => 'Item not found.']);
    }
    if ($row['kind'] === 'consumable') {
        json(400, ['error' => 'That item cannot be equipped — use it instead.']);
    }

    $allowed = Item::slotsForType($row['slot_type']);

    // Honour a requested slot if valid, else the first free one, else replace
    // whatever's in the first allowed slot.
    if ($wantedSlot !== null) {
        if (!in_array($wantedSlot, $allowed, true)) {
            json(400, ['error' => 'That item does not fit that slot.']);
        }
        $slot = $wantedSlot;
    } else {
        $slot = $repo->firstFreeSlot($charId, $allowed) ?? $allowed[0];
    }

    $repo->equip($charId, $charItemId, $slot);
    handleMyCharacter();
}

function handleUnequip(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $slot = (string)(body()['slot'] ?? '');
    itemRepo()->unequipSlot($charId, $slot);

    handleMyCharacter();
}

function handleUseItem(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $repo       = itemRepo();
    $charItemId = (int)(body()['char_item_id'] ?? 0);
    $row        = $repo->instance($charItemId, $charId);
    if (!$row) {
        json(404, ['error' => 'Item not found.']);
    }
    if ($row['kind'] !== 'consumable') {
        json(400, ['error' => 'That item cannot be used.']);
    }

    tickCharacterRegen($charId); // settle passive regen before healing

    $db = db();
    $ch = $db->prepare('SELECT hp, hp_max FROM characters WHERE id = ?');
    $ch->execute([$charId]);
    $c = $ch->fetch();

    $effMax = (int)$c['hp_max'] + equippedHpBonus($charId);
    $newHp  = min($effMax, (int)$c['hp'] + (int)$row['heal_hp']);
    $healed = $newHp - (int)$c['hp'];

    $db->prepare('UPDATE characters SET hp = ? WHERE id = ?')->execute([$newHp, $charId]);
    $repo->deleteInstance($charItemId);

    json(200, [
        'healed'    => $healed,
        'name'      => $row['name'],
        'character' => loadCharacter($charId),
    ]);
}

function handleSellItem(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $repo       = itemRepo();
    $charItemId = (int)(body()['char_item_id'] ?? 0);
    $row        = $repo->instance($charItemId, $charId);
    if (!$row) {
        json(404, ['error' => 'Item not found.']);
    }
    if ($row['equipped_slot'] !== null) {
        json(400, ['error' => 'Unequip the item before selling it.']);
    }

    $gold = (int)$row['sell_value'];

    // Credit the primary settlement (settle production first, then add).
    $db  = db();
    $sel = $db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
    $sel->execute([$player['id']]);
    if ($settlement = $sel->fetch()) {
        tickSettlement($settlement);
        $db->prepare('UPDATE settlements SET gold = LEAST(capacity_gold, gold + ?) WHERE id = ?')
           ->execute([$gold, $settlement['id']]);
    }

    $repo->deleteInstance($charItemId);

    json(200, [
        'sold'      => $row['name'],
        'gold'      => $gold,
        'character' => loadCharacter($charId),
    ]);
}
