<?php
declare(strict_types=1);

namespace Gob\Domain;

// A thing scattered through a province: minor/boon/dungeon/road. Pure — the
// next-stage monster lookup is done by the caller and passed to toArray().
final class Site
{
    public function __construct(private array $row) {}

    public function stages(): array
    {
        return $this->row['stages_json'] ? json_decode($this->row['stages_json'], true) : [];
    }

    // The monster id guarding the next stage (for a found, unfinished site).
    public function nextMonsterId(): ?int
    {
        $stages = $this->stages();
        if ($this->row['state'] === 'found' && isset($stages[(int)$this->row['progress']])) {
            return (int)$stages[(int)$this->row['progress']];
        }
        return null;
    }

    // $nextMonster is ['name'=>…, 'description'=>…] or null.
    public function toArray(?array $nextMonster = null): array
    {
        $s = $this->row;
        return [
            'id'                => (int)$s['id'],
            'type'              => $s['type'],
            'name'              => $s['name'],
            'state'             => $s['state'],
            'progress'          => (int)$s['progress'],
            'total_stages'      => count($this->stages()),
            'next_monster'      => $nextMonster['name'] ?? null,
            'next_monster_desc' => $nextMonster['description'] ?? null,
            'road_terrain'      => $s['road_terrain'],
            'reward'            => [
                'gold'       => (int)$s['reward_gold'],
                'gold_rate'  => (int)$s['bonus_gold_rate'],
                'wood_rate'  => (int)$s['bonus_wood_rate'],
                'stone_rate' => (int)$s['bonus_stone_rate'],
                'regen'      => (int)$s['bonus_regen'],
                'item_id'    => $s['reward_item_id'] !== null ? (int)$s['reward_item_id'] : null,
            ],
        ];
    }
}
