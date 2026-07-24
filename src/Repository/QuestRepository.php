<?php
declare(strict_types=1);

namespace Gob\Repository;

use Gob\Domain\Quest;
use Gob\Repositories;
use PDO;

// Database access for quest instances plus offer/progress logic.
final class QuestRepository
{
    public function __construct(private PDO $db) {}

    private function items(): ItemRepository
    {
        return Repositories::get(ItemRepository::class);
    }

    // The player's active/done quests (turned-in ones hidden).
    public function activeForPlayer(int $playerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM player_quests WHERE player_id = ? AND state <> 'turned_in' ORDER BY id"
        );
        $stmt->execute([$playerId]);
        return $stmt->fetchAll();
    }

    public function find(int $id, int $playerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM player_quests WHERE id = ? AND player_id = ?');
        $stmt->execute([$id, $playerId]);
        return $stmt->fetch() ?: null;
    }

    public function has(int $playerId, string $key): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM player_quests WHERE player_id = ? AND template_key = ?');
        $stmt->execute([$playerId, $key]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // The template an NPC will offer now (or null): profession matches and the
    // player doesn't already hold that quest.
    public function offerFor(int $playerId, array $npc): ?array
    {
        foreach (Quest::TEMPLATES as $key => $t) {
            if ($t['giver'] !== $npc['profession']) {
                continue;
            }
            if (!$this->has($playerId, $key)) {
                return ['key' => $key] + $t;
            }
        }
        return null;
    }

    // Create a quest instance from an offer; returns its id.
    public function create(int $playerId, int $npcId, array $offer): int
    {
        $this->db->prepare(
            'INSERT INTO player_quests
                (player_id, giver_npc_id, template_key, title, objective, target_race, target_count, reward_gold, reward_rep)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $playerId, $npcId, $offer['key'], $offer['title'], $offer['objective'],
            $offer['target_race'], $offer['target_count'], $offer['reward_gold'], $offer['reward_rep'],
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function markTurnedIn(int $id): void
    {
        $this->db->prepare("UPDATE player_quests SET state = 'turned_in' WHERE id = ?")->execute([$id]);
    }

    // Proof requirement + how many the player holds (null if none needed).
    public function proofFor(int $playerId, array $q): ?array
    {
        $t = Quest::template($q['template_key']);
        if (!$t || empty($t['proof_item_id'])) {
            return null;
        }
        $items = $this->items();
        return [
            'item' => $items->name((int)$t['proof_item_id']),
            'need' => (int)$t['proof_count'],
            'have' => $items->countForPlayer($playerId, (int)$t['proof_item_id']),
        ];
    }

    // On a winning fight vs `race`, advance active kill-quests; flip to done
    // when the target count is met.
    public function advanceKills(int $playerId, string $race): void
    {
        if ($race === '') {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM player_quests
             WHERE player_id = ? AND state = 'active' AND objective = 'kill' AND target_race = ?"
        );
        $stmt->execute([$playerId, $race]);
        foreach ($stmt->fetchAll() as $q) {
            $progress = (int)$q['progress'] + 1;
            $done     = $progress >= (int)$q['target_count'];
            $this->db->prepare('UPDATE player_quests SET progress = ?, state = ? WHERE id = ?')
                     ->execute([$progress, $done ? 'done' : 'active', (int)$q['id']]);
        }
    }
}
