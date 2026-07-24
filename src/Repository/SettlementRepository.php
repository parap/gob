<?php
declare(strict_types=1);

namespace Gob\Repository;

use PDO;

// Database access for settlements, including the "rates + timestamps" accrual
// tick (resources catch up whenever a settlement is read/written).
final class SettlementRepository
{
    public function __construct(private PDO $db) {}

    // Give a new player their starting settlement (schema defaults do the rest).
    public function createStarting(int $playerId): void
    {
        $this->db->prepare('INSERT INTO settlements (player_id, name, terrain) VALUES (?, ?, ?)')
                 ->execute([$playerId, 'Capital', 'plains']);
    }

    // All of a player's settlement rows, oldest first.
    public function forPlayer(int $playerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id');
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    // The player's home (primary) settlement id, or null.
    public function homeId(int $playerId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$playerId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    // The home village's reputation (own-race standing).
    public function reputation(int $playerId): int
    {
        $stmt = $this->db->prepare('SELECT reputation FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$playerId]);
        return (int)$stmt->fetchColumn();
    }

    public function addReputation(int $playerId, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        $this->db->prepare('UPDATE settlements SET reputation = reputation + ? WHERE player_id = ? ORDER BY id LIMIT 1')
                 ->execute([$amount, $playerId]);
    }

    // The home settlement's terrain (used to seed the home province).
    public function homeTerrain(int $playerId): ?string
    {
        $stmt = $this->db->prepare('SELECT terrain FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $stmt->execute([$playerId]);
        $t = $stmt->fetchColumn();
        return $t !== false ? (string)$t : null;
    }

    // Credit gold to the primary settlement (settle production first, clamp to cap).
    public function addGold(int $playerId, int $gold): void
    {
        if ($gold <= 0) {
            return;
        }
        $sel = $this->db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $sel->execute([$playerId]);
        if ($s = $sel->fetch()) {
            $this->tick($s);
            $this->db->prepare('UPDATE settlements SET gold = LEAST(capacity_gold, gold + ?) WHERE id = ?')
                     ->execute([$gold, $s['id']]);
        }
    }

    // Adjust the primary settlement's production rates (settle first; floor at 0).
    public function adjustRates(int $playerId, int $gold, int $wood, int $stone): void
    {
        if (!$gold && !$wood && !$stone) {
            return;
        }
        $sel = $this->db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $sel->execute([$playerId]);
        if ($s = $sel->fetch()) {
            $this->tick($s);
            $this->db->prepare(
                'UPDATE settlements SET
                    rate_gold_per_hour  = GREATEST(0, rate_gold_per_hour  + ?),
                    rate_wood_per_hour  = GREATEST(0, rate_wood_per_hour  + ?),
                    rate_stone_per_hour = GREATEST(0, rate_stone_per_hour + ?)
                 WHERE id = ?'
            )->execute([$gold, $wood, $stone, $s['id']]);
        }
    }

    // Apply production accrued since last_tick, persist it, and return the
    // updated row. Takes the row the caller already fetched.
    public function tick(array $s): array
    {
        $elapsedHours = (time() - strtotime($s['last_tick'])) / 3600;
        if ($elapsedHours <= 0) {
            return $s;
        }

        foreach (['gold', 'wood', 'stone'] as $res) {
            $rate = (int)$s["rate_{$res}_per_hour"];
            $cap  = (int)$s["capacity_{$res}"];
            $v    = (int)round((int)$s[$res] + $rate * $elapsedHours);
            if ($cap > 0) {
                $v = min($cap, $v);
            }
            $s[$res] = max(0, $v);
        }

        $this->db->prepare(
            'UPDATE settlements SET gold = ?, wood = ?, stone = ?, last_tick = NOW() WHERE id = ?'
        )->execute([$s['gold'], $s['wood'], $s['stone'], $s['id']]);

        return $s;
    }
}
