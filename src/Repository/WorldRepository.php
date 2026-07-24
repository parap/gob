<?php
declare(strict_types=1);

namespace Gob\Repository;

use PDO;

// Database access for the province world: provinces, their scattered sites,
// and the roads between them. Generation logic and orchestration stay in
// world.php; this class is purely the SQL.
final class WorldRepository
{
    public function __construct(private PDO $db) {}

    // ── Provinces ────────────────────────────────────────────────────────────

    public function createProvince(int $playerId, string $name, string $terrain, int $level, bool $isHome): int
    {
        $this->db->prepare('INSERT INTO provinces (player_id, name, terrain, level, is_home) VALUES (?, ?, ?, ?, ?)')
                 ->execute([$playerId, $name, $terrain, $level, $isHome ? 1 : 0]);
        return (int)$this->db->lastInsertId();
    }

    public function provincesForPlayer(int $playerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM provinces WHERE player_id = ? ORDER BY id');
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    public function findProvince(int $id, int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM provinces WHERE id = ? AND player_id = ?');
        $stmt->execute([$id, $playerId]);
        return $stmt->fetch() ?: null;
    }

    // Name + terrain of a province (for the "new province" notice).
    public function provinceBrief(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT name, terrain FROM provinces WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function updateExplored(int $id, float $pct): void
    {
        $this->db->prepare('UPDATE provinces SET explored_pct = ? WHERE id = ?')->execute([$pct, $id]);
    }

    public function currentProvinceId(int $charId): ?int
    {
        $stmt = $this->db->prepare('SELECT current_province_id FROM characters WHERE id = ?');
        $stmt->execute([$charId]);
        $cur = $stmt->fetchColumn();
        return $cur ? (int)$cur : null;
    }

    public function setCurrentProvince(int $charId, int $provinceId): void
    {
        $this->db->prepare('UPDATE characters SET current_province_id = ? WHERE id = ?')->execute([$provinceId, $charId]);
    }

    // ── Sites ────────────────────────────────────────────────────────────────

    // Insert a generated site. Missing keys fall back to the schema defaults
    // (state 'hidden', progress 0, zero rewards).
    public function insertSite(array $f): void
    {
        $this->db->prepare(
            'INSERT INTO province_sites
                (province_id, player_id, type, name, position, concealment, state, progress,
                 reward_gold, bonus_gold_rate, bonus_wood_rate, bonus_stone_rate, bonus_regen,
                 reward_item_id, road_terrain, stages_json, description)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $f['province_id'], $f['player_id'], $f['type'], $f['name'], $f['position'],
            $f['concealment'], $f['state'] ?? 'hidden', $f['progress'] ?? 0,
            $f['reward_gold'] ?? 0, $f['bonus_gold_rate'] ?? 0, $f['bonus_wood_rate'] ?? 0,
            $f['bonus_stone_rate'] ?? 0, $f['bonus_regen'] ?? 0, $f['reward_item_id'] ?? null,
            $f['road_terrain'] ?? null, $f['stages_json'] ?? null, $f['description'] ?? null,
        ]);
    }

    public function findSite(int $id, int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM province_sites WHERE id = ? AND player_id = ?');
        $stmt->execute([$id, $playerId]);
        return $stmt->fetch() ?: null;
    }

    // Still-hidden sites in a province whose position the sweep has reached.
    public function hiddenSweptSites(int $provinceId, float $pct): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM province_sites WHERE province_id = ? AND state = "hidden" AND position <= ?'
        );
        $stmt->execute([$provinceId, $pct]);
        return $stmt->fetchAll();
    }

    // Discovered (found/cleared) sites across all the player's provinces.
    public function visibleSites(int $playerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM province_sites WHERE player_id = ? AND state <> "hidden" ORDER BY province_id, position'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    public function setSiteState(int $id, string $state): void
    {
        $this->db->prepare('UPDATE province_sites SET state = ? WHERE id = ?')->execute([$state, $id]);
    }

    public function setSiteProgress(int $id, int $progress): void
    {
        $this->db->prepare('UPDATE province_sites SET progress = ? WHERE id = ?')->execute([$progress, $id]);
    }

    public function setSiteProgressState(int $id, int $progress, string $state): void
    {
        $this->db->prepare('UPDATE province_sites SET progress = ?, state = ? WHERE id = ?')
                 ->execute([$progress, $state, $id]);
    }

    // A random still-hidden dungeon (a raid's origin), or null.
    public function randomHiddenDungeon(int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM province_sites WHERE player_id = ? AND type = "dungeon" AND state = "hidden" ORDER BY RAND() LIMIT 1'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetch() ?: null;
    }

    // A random cleared boon that still grants a bonus (a raid can retake it), or null.
    public function randomClearedBoon(int $playerId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM province_sites WHERE player_id = ? AND type = "boon" AND state = "cleared"
             AND (bonus_gold_rate>0 OR bonus_wood_rate>0 OR bonus_stone_rate>0 OR bonus_regen>0)
             ORDER BY RAND() LIMIT 1'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetch() ?: null;
    }

    // ── Roads (links) ─────────────────────────────────────────────────────────

    public function link(int $playerId, int $x, int $y): void
    {
        $this->db->prepare('INSERT IGNORE INTO province_links (player_id, a, b) VALUES (?, ?, ?)')
                 ->execute([$playerId, min($x, $y), max($x, $y)]);
    }
}
