<?php
declare(strict_types=1);

namespace Gob\Domain;

// A PvE enemy definition (a row from `monsters`) plus its classification tags.
// Pure; combat reads the raw row, the API uses toArray().
final class Monster
{
    public function __construct(private array $row, private array $tags = []) {}

    public function id(): int { return (int)$this->row['id']; }
    public function row(): array { return $this->row; }

    public function toArray(): array
    {
        $m = $this->row;
        return [
            'id'          => (int)$m['id'],
            'name'        => $m['name'],
            'level'       => (int)$m['level'],
            'hp'          => (int)$m['hp'],
            'attack'      => (int)$m['attack'],
            'defense'     => (int)$m['defense'],
            'protection'  => (int)$m['protection'],
            'penetration' => (int)$m['penetration'],
            'reward_gold' => (int)$m['reward_gold'],
            'race'        => $m['race'] ?? 'unknown',
            'alignment'   => $m['alignment'] ?? 'neutral',
            'tags'        => $this->tags,
            'description' => $m['description'] ?? '',
        ];
    }
}
