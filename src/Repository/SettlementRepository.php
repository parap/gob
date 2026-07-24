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
