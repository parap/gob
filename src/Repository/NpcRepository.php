<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Npc;
use Gob\Domain\Tutor;
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
            'INSERT INTO npcs (player_id, race, profession, name, settlement_id, teaches, teach_ceiling)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (Npc::VILLAGE_ROSTER as $n) {
            // Roll this tutor's ceiling once, now, so it is a fixed property of
            // the person rather than something rerolled each lesson. A resident
            // teaching someone else's tongue only ever has fragments of it.
            $teaches = $n['teaches'] ?? null;
            $ceiling = $teaches === null
                ? 0
                : Tutor::rollCeiling($teaches === $n['race']);
            $ins->execute([$playerId, $n['race'], $n['profession'], $n['name'], $settlementId, $teaches, $ceiling]);
        }
    }

    // Resident NPC rows of a settlement, ordered.
    public function residents(int $playerId, int $settlementId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM npcs WHERE player_id = ? AND settlement_id = ? ORDER BY id');
        $stmt->execute([$playerId, $settlementId]);
        return $stmt->fetchAll();
    }

    // Turn a spared, questioned enemy into a persistent individual (§2/§8): it
    // survived and it mattered, so it stops being a spawn and becomes someone.
    // At most one per race per province — enough to have a contact out there,
    // not so many that "known individual" stops meaning anything. Returns the
    // new NPC's id, or null if this race already has someone here.
    public function promote(int $playerId, array $monster, ?int $provinceId, ?int $siteId): ?int
    {
        $race = (string)($monster['race'] ?? 'unknown');
        if ($provinceId === null) {
            return null;
        }

        $chk = $this->db->prepare(
            'SELECT id FROM npcs WHERE player_id = ? AND race = ? AND province_id = ? AND monster_id IS NOT NULL LIMIT 1'
        );
        $chk->execute([$playerId, $race, $provinceId]);
        if ($chk->fetchColumn()) {
            return null;
        }

        // They can teach their own tongue, and natives know it far better than
        // any village scholar — but whether they *will* is the relationship's
        // business, checked when the offer is built.
        $this->db->prepare(
            'INSERT INTO npcs (player_id, race, profession, name, monster_id, site_id, province_id, teaches, teach_ceiling)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $playerId, $race, Npc::SURVIVOR, Npc::generateName(),
            (int)$monster['id'], $siteId, $provinceId,
            $race, Tutor::rollCeiling(true),
        ]);
        return (int)$this->db->lastInsertId();
    }

    // Individuals the player knows in a province — promoted survivors, not
    // village residents.
    public function contactsIn(int $playerId, ?int $provinceId): array
    {
        if ($provinceId === null) {
            return [];
        }
        $stmt = $this->db->prepare(
            'SELECT * FROM npcs WHERE player_id = ? AND province_id = ? AND monster_id IS NOT NULL ORDER BY id'
        );
        $stmt->execute([$playerId, $provinceId]);
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
