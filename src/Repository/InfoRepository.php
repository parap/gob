<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Fact;
use PDO;

// Storage for the information model (§7): authored facts, and what each player
// has perceived. Facts themselves are global — only the journal is per player.
final class InfoRepository
{
    public function __construct(private PDO $db) {}

    /** @return Fact[] */
    public function factsFor(string $subjectType, string $subjectRef): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM info_facts WHERE subject_type = ? AND subject_ref = ? ORDER BY id'
        );
        $stmt->execute([$subjectType, $subjectRef]);
        return array_map(fn(array $r) => new Fact($r), $stmt->fetchAll());
    }

    // Write newly-perceived facts into the journal. Existing entries are left
    // alone so a fact already Shared is not demoted back to Remembered.
    public function remember(int $playerId, array $factIds): void
    {
        if (!$factIds) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT IGNORE INTO player_knowledge (player_id, fact_id, state) VALUES (?, ?, "remembered")'
        );
        foreach ($factIds as $id) {
            $stmt->execute([$playerId, (int)$id]);
        }
    }

    // The journal, newest first, with each fact's subject for grouping.
    public function journal(int $playerId): array
    {
        $stmt = $this->db->prepare(
            'SELECT f.id, f.subject_type, f.subject_ref, f.channel, f.content,
                    k.state, k.learned_at
             FROM player_knowledge k
             JOIN info_facts f ON f.id = k.fact_id
             WHERE k.player_id = ?
             ORDER BY k.learned_at DESC, f.id DESC'
        );
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    // How many facts exist for a subject, so the journal can show progress
    // ("4 of 11 about goblins") without leaking what the rest are.
    public function countFor(string $subjectType, string $subjectRef): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM info_facts WHERE subject_type = ? AND subject_ref = ?'
        );
        $stmt->execute([$subjectType, $subjectRef]);
        return (int)$stmt->fetchColumn();
    }
}
