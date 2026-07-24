<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Item;
use PDO;

// Database access for items: owned-instance queries, granting, equipping,
// and instance lookups/removal.
final class ItemRepository
{
    public function __construct(private PDO $db) {}

    // All items a character owns (equipped + backpack), as detail arrays.
    public function owned(int $charId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.id AS ci_id, ci.item_id, ci.equipped_slot,
                    i.name, i.slot_type, i.rarity, i.weapon_skill,
                    i.bonus_str, i.bonus_dex, i.bonus_con, i.bonus_int, i.bonus_wis, i.bonus_cha,
                    i.bonus_hp, i.bonus_mana, i.bonus_courage,
                    i.bonus_defense, i.bonus_protection, i.bonus_attack, i.bonus_penetration,
                    i.bonus_perception, i.kind, i.heal_hp, i.sell_value, i.description
             FROM character_items ci
             JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ?
             ORDER BY i.name'
        );
        $stmt->execute([$charId]);
        return array_map(fn(array $r) => (new Item($r))->toArray(), $stmt->fetchAll());
    }

    // Give a character an item instance (to the backpack, unequipped).
    public function grant(int $charId, int $itemId): void
    {
        $this->db->prepare('INSERT INTO character_items (character_id, item_id) VALUES (?, ?)')
                 ->execute([$charId, $itemId]);
    }

    // An item definition's display name.
    public function name(int $itemId): string
    {
        $stmt = $this->db->prepare('SELECT name FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        return (string)$stmt->fetchColumn();
    }

    // How many of an item the player's character owns (across all instances).
    public function countForPlayer(int $playerId, int $itemId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM character_items ci
             JOIN characters c ON c.id = ci.character_id
             WHERE c.player_id = ? AND ci.item_id = ?'
        );
        $stmt->execute([$playerId, $itemId]);
        return (int)$stmt->fetchColumn();
    }

    // Remove up to $n instances of an item from a character (e.g. quest proof).
    public function consume(int $charId, int $itemId, int $n): void
    {
        // $n is a trusted int; inline to avoid driver LIMIT-binding issues.
        $this->db->prepare(
            'DELETE FROM character_items WHERE character_id = ? AND item_id = ? ORDER BY id LIMIT ' . (int)$n
        )->execute([$charId, $itemId]);
    }

    // A single owned instance (joined with its definition) for validation, or null.
    public function instance(int $charItemId, int $charId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ci.id, ci.equipped_slot, i.slot_type, i.kind, i.heal_hp, i.sell_value, i.name, i.weapon_skill
             FROM character_items ci JOIN items i ON i.id = ci.item_id
             WHERE ci.id = ? AND ci.character_id = ?'
        );
        $stmt->execute([$charItemId, $charId]);
        return $stmt->fetch() ?: null;
    }

    // The first of $allowed that isn't currently occupied, or null.
    public function firstFreeSlot(int $charId, array $allowed): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT equipped_slot FROM character_items WHERE character_id = ? AND equipped_slot IS NOT NULL'
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

    // Move an instance into a slot, clearing whatever was there (atomic).
    public function equip(int $charId, int $charItemId, string $slot): void
    {
        $this->db->beginTransaction();
        $this->db->prepare('UPDATE character_items SET equipped_slot = NULL WHERE character_id = ? AND equipped_slot = ?')
                 ->execute([$charId, $slot]);
        $this->db->prepare('UPDATE character_items SET equipped_slot = ? WHERE id = ?')
                 ->execute([$slot, $charItemId]);
        $this->db->commit();
    }

    public function unequipSlot(int $charId, string $slot): void
    {
        $this->db->prepare('UPDATE character_items SET equipped_slot = NULL WHERE character_id = ? AND equipped_slot = ?')
                 ->execute([$charId, $slot]);
    }

    public function deleteInstance(int $charItemId): void
    {
        $this->db->prepare('DELETE FROM character_items WHERE id = ?')->execute([$charItemId]);
    }
}
