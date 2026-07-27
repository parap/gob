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
        // Starter gear goes straight onto the hero rather than into the
        // backpack — otherwise a new player walks into the first goblin fight
        // bare-handed with a sword they never noticed.
        $items = $this->items();
        foreach (Character::STARTER_ITEMS as $itemId) {
            $items->equipIfFree($charId, $items->grant($charId, $itemId));
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

    // --- Mercy stance & window -------------------------------------------

    public function setMercy(int $charId, bool $on): void
    {
        $this->db->prepare('UPDATE characters SET mercy = ? WHERE id = ?')->execute([$on ? 1 : 0, $charId]);
    }

    public function mercyStance(int $charId): bool
    {
        $stmt = $this->db->prepare('SELECT mercy FROM characters WHERE id = ?');
        $stmt->execute([$charId]);
        return (bool)$stmt->fetchColumn();
    }

    // Remember the enemy left alive, where it happened, and when — the deed
    // itself isn't recorded until the window resolves.
    public function openMercyWindow(int $charId, int $monsterId, ?int $siteId, ?int $provinceId): void
    {
        $this->db->prepare(
            'UPDATE characters
             SET spared_monster_id = ?, spared_site_id = ?, spared_province_id = ?, spared_at = NOW()
             WHERE id = ?'
        )->execute([$monsterId, $siteId, $provinceId, $charId]);
    }

    // The open window with its remaining seconds, or null. `expired` marks a
    // window whose time ran out but which hasn't been settled yet.
    public function mercyWindow(int $charId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT spared_monster_id, spared_site_id, spared_province_id, spared_at
             FROM characters WHERE id = ?'
        );
        $stmt->execute([$charId]);
        $row = $stmt->fetch();
        if (!$row || $row['spared_monster_id'] === null || $row['spared_at'] === null) {
            return null;
        }
        $left = Character::MERCY_WINDOW_SECONDS - (time() - strtotime($row['spared_at']));
        return [
            'monster_id'   => (int)$row['spared_monster_id'],
            'site_id'      => $row['spared_site_id'] !== null ? (int)$row['spared_site_id'] : null,
            'province_id'  => $row['spared_province_id'] !== null ? (int)$row['spared_province_id'] : null,
            'seconds_left' => max(0, $left),
            'expired'      => $left <= 0,
        ];
    }

    public function clearMercyWindow(int $charId): void
    {
        $this->db->prepare(
            'UPDATE characters
             SET spared_monster_id = NULL, spared_site_id = NULL, spared_province_id = NULL, spared_at = NULL
             WHERE id = ?'
        )->execute([$charId]);
    }

    // --- Training (the one tuition slot, §5) ------------------------------

    public function training(int $charId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT training_skill, training_gain, training_ends_at FROM characters WHERE id = ?'
        );
        $stmt->execute([$charId]);
        $row = $stmt->fetch();
        if (!$row || $row['training_skill'] === null || $row['training_ends_at'] === null) {
            return null;
        }
        return [
            'skill'        => (string)$row['training_skill'],
            'gain'         => (int)$row['training_gain'],
            'seconds_left' => max(0, strtotime($row['training_ends_at']) - time()),
        ];
    }

    public function startTraining(int $charId, string $skill, int $gain, int $seconds): void
    {
        // $seconds is a trusted computed int; inlined to sidestep driver
        // quirks binding a parameter inside INTERVAL (same as LIMIT elsewhere).
        $this->db->prepare(
            'UPDATE characters
             SET training_skill = ?, training_gain = ?, training_ends_at = NOW() + INTERVAL ' . (int)$seconds . ' SECOND
             WHERE id = ?'
        )->execute([$skill, $gain, $charId]);
    }

    // Bank a finished lesson. Called on every character read, so a session
    // lands whenever the player next looks — no worker, same as regen.
    public function settleTraining(int $charId): void
    {
        $t = $this->training($charId);
        if ($t === null || $t['seconds_left'] > 0) {
            return;
        }
        $this->raiseSkill($charId, $t['skill'], $t['gain']);
        $this->db->prepare(
            'UPDATE characters SET training_skill = NULL, training_gain = 0, training_ends_at = NULL WHERE id = ?'
        )->execute([$charId]);
    }

    // Add to a skill, creating it if the character never had it — which is how
    // a language starts existing at all.
    public function raiseSkill(int $charId, string $skill, int $amount): void
    {
        $this->db->prepare(
            'INSERT INTO character_skills (character_id, skill, value) VALUES (?, ?, LEAST(100, ?))
             ON DUPLICATE KEY UPDATE value = LEAST(100, value + VALUES(value))'
        )->execute([$charId, $skill, $amount]);
    }

    // Load the full character (regen and any finished lesson applied first).
    public function load(int $charId): Character
    {
        $this->regen($charId);
        $this->settleTraining($charId);

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
