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
