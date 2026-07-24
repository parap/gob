<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Character;
use Gob\Repositories;
use PDO;

// All database access for the hero. Hands back Character domain objects.
// Item persistence is delegated to ItemRepository.
final class CharacterRepository
{
    public function __construct(private PDO $db) {}

    private function items(): ItemRepository
    {
        return Repositories::get(ItemRepository::class);
    }

    // Create the character (plus its skills and starter items) if the player
    // doesn't have one yet. Safe to call repeatedly. Returns the character id.
    public function ensure(int $playerId, string $name): int
    {
        $stmt = $this->db->prepare('SELECT id FROM characters WHERE player_id = ?');
        $stmt->execute([$playerId]);
        if ($row = $stmt->fetch()) {
            return (int)$row['id'];
        }

        $this->db->prepare('INSERT INTO characters (player_id, name) VALUES (?, ?)')
                 ->execute([$playerId, $name]);
        $charId = (int)$this->db->lastInsertId();

        $skill = $this->db->prepare('INSERT INTO character_skills (character_id, skill) VALUES (?, ?)');
        foreach (Character::SKILLS as $s) {
            $skill->execute([$charId, $s]);
        }
        foreach (Character::STARTER_ITEMS as $itemId) {
            $this->items()->grant($charId, $itemId);
        }
        return $charId;
    }

    // Total bonus_hp from equipped items (raises the effective HP max).
    public function equippedHpBonus(int $charId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(i.bonus_hp), 0)
             FROM character_items ci JOIN items i ON i.id = ci.item_id
             WHERE ci.character_id = ? AND ci.equipped_slot IS NOT NULL'
        );
        $stmt->execute([$charId]);
        return (int)$stmt->fetchColumn();
    }

    // Apply HP regenerated since last_regen_at, then stamp the marker.
    public function regen(int $charId): void
    {
        $stmt = $this->db->prepare('SELECT hp, hp_max, regen_bonus, last_regen_at FROM characters WHERE id = ?');
        $stmt->execute([$charId]);
        $row = $stmt->fetch();
        if (!$row) {
            return;
        }
        if ($row['last_regen_at'] === null) {
            $this->db->prepare('UPDATE characters SET last_regen_at = NOW() WHERE id = ?')->execute([$charId]);
            return;
        }

        $ratePerMin = Character::HP_REGEN_PER_MIN + (int)$row['regen_bonus'];
        $elapsed    = time() - strtotime($row['last_regen_at']);
        $regen      = (int)floor($elapsed * $ratePerMin / 60);
        if ($regen <= 0) {
            return;
        }

        $effMax = (int)$row['hp_max'] + $this->equippedHpBonus($charId);
        $newHp  = min($effMax, (int)$row['hp'] + $regen);
        $this->db->prepare('UPDATE characters SET hp = ?, last_regen_at = NOW() WHERE id = ?')
                 ->execute([$newHp, $charId]);
    }

    // Change the hero's passive regen bonus (settle current regen first; floor at 0).
    public function adjustRegenBonus(int $charId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $this->regen($charId);
        $this->db->prepare('UPDATE characters SET regen_bonus = GREATEST(0, regen_bonus + ?) WHERE id = ?')
                 ->execute([$delta, $charId]);
    }

    // Seconds left on the explore cooldown (0 if ready / never explored).
    public function exploreCooldownRemaining(int $charId, int $seconds): int
    {
        $stmt = $this->db->prepare('SELECT last_explore_at FROM characters WHERE id = ?');
        $stmt->execute([$charId]);
        $last = $stmt->fetchColumn();
        if (!$last) {
            return 0;
        }
        return max(0, $seconds - (time() - strtotime($last)));
    }

    public function stampExplore(int $charId): void
    {
        $this->db->prepare('UPDATE characters SET last_explore_at = NOW() WHERE id = ?')->execute([$charId]);
    }

    // Load the full character (regen applied first) as a domain object.
    public function load(int $charId): Character
    {
        $this->regen($charId);

        $stmt = $this->db->prepare('SELECT * FROM characters WHERE id = ?');
        $stmt->execute([$charId]);
        $row = $stmt->fetch();

        $stmt = $this->db->prepare('SELECT skill, value FROM character_skills WHERE character_id = ? ORDER BY skill');
        $stmt->execute([$charId]);
        $skills = [];
        foreach ($stmt->fetchAll() as $s) {
            $skills[$s['skill']] = (int)$s['value'];
        }

        return new Character($row, $skills, $this->items()->owned($charId));
    }
}
