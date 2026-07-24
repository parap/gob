<?php
declare(strict_types=1);

namespace Gob\Domain;

// A per-player province (a terrain tile the hero explores). Pure.
final class Province
{
    public function __construct(private array $row, private bool $isCurrent = false) {}

    public function id(): int { return (int)$this->row['id']; }

    public function toArray(): array
    {
        $p = $this->row;
        return [
            'id'           => (int)$p['id'],
            'name'         => $p['name'],
            'terrain'      => $p['terrain'],
            'level'        => (int)$p['level'],
            'is_home'      => (int)$p['is_home'] === 1,
            'is_current'   => $this->isCurrent,
            'explored_pct' => (float)$p['explored_pct'],
        ];
    }
}
