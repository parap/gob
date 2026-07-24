<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Monster;
use PDO;

// Database access for PvE monster definitions and their tags.
final class MonsterRepository
{
    public function __construct(private PDO $db) {}

    // All monsters (each with its tags), ordered by level then id.
    public function all(): array
    {
        $rows = $this->db->query('SELECT * FROM monsters ORDER BY level, id')->fetchAll();
        $tags = $this->tags();
        return array_map(fn(array $r) => new Monster($r, $tags[(int)$r['id']] ?? []), $rows);
    }

    // A single monster row by id, or null. Raw row — combat reads its fields.
    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM monsters WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    // Candidate monster ids for world-gen: those whose race or tag is in $pool
    // and whose level sits in a band around $level. Falls back to any in-band
    // monster, then to [1], so a province can always be populated.
    public function terrainCandidates(array $pool, int $level): array
    {
        $lo = max(1, $level - 2);
        $hi = $level + 3;

        if ($pool) {
            $ph   = implode(',', array_fill(0, count($pool), '?'));
            $stmt = $this->db->prepare(
                "SELECT DISTINCT m.id FROM monsters m
                 LEFT JOIN monster_tags t ON t.monster_id = m.id
                 WHERE (m.race IN ($ph) OR t.tag IN ($ph)) AND m.level BETWEEN ? AND ?"
            );
            $stmt->execute([...$pool, ...$pool, $lo, $hi]);
            $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
            if ($ids) {
                return $ids;
            }
        }

        $stmt = $this->db->prepare('SELECT id FROM monsters WHERE level BETWEEN ? AND ?');
        $stmt->execute([$lo, $hi]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
        return $ids ?: [1];
    }

    // monster_id => [tag, ...]
    public function tags(): array
    {
        $out = [];
        foreach ($this->db->query('SELECT monster_id, tag FROM monster_tags ORDER BY tag')->fetchAll() as $t) {
            $out[(int)$t['monster_id']][] = $t['tag'];
        }
        return $out;
    }
}
