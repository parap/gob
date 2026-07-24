<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Npc;
use PDO;

// Database access for NPCs (the unified `npcs` table).
final class NpcRepository
{
    public function __construct(private PDO $db) {}

    // Populate a home village with its resident NPCs, once. Idempotent.
    public function ensureVillage(int $playerId, int $settlementId): void
    {
        $chk = $this->db->prepare('SELECT COUNT(*) FROM npcs WHERE player_id = ? AND settlement_id = ?');
        $chk->execute([$playerId, $settlementId]);
        if ((int)$chk->fetchColumn() > 0) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO npcs (player_id, race, profession, name, settlement_id) VALUES (?, ?, ?, ?, ?)'
        );
        foreach (Npc::VILLAGE_ROSTER as $n) {
            $ins->execute([$playerId, $n['race'], $n['profession'], $n['name'], $settlementId]);
        }
    }

    // Resident NPC rows of a settlement, ordered.
    public function residents(int $playerId, int $settlementId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM npcs WHERE player_id = ? AND settlement_id = ? ORDER BY id');
        $stmt->execute([$playerId, $settlementId]);
        return $stmt->fetchAll();
    }

    // A single NPC row owned by the player, or null.
    public function find(int $id, int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM npcs WHERE id = ? AND player_id = ?');
        $stmt->execute([$id, $playerId]);
        return $stmt->fetch() ?: null;
    }
}
