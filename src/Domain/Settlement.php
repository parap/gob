<?php
declare(strict_types=1);

namespace Gob\Domain;

// A player's settlement: stored resources plus production rates and caps.
// Pure — persistence and the accrual tick live in SettlementRepository.
final class Settlement
{
    public function __construct(private array $row) {}

    public function id(): int { return (int)$this->row['id']; }

    // The JSON the frontend expects (ints, no internal columns like reputation).
    public function toArray(): array
    {
        $s = $this->row;
        return [
            'id'                  => (int)$s['id'],
            'name'                => $s['name'],
            'terrain'             => $s['terrain'],
            'gold'                => (int)$s['gold'],
            'wood'                => (int)$s['wood'],
            'stone'               => (int)$s['stone'],
            'rate_gold_per_hour'  => (int)$s['rate_gold_per_hour'],
            'rate_wood_per_hour'  => (int)$s['rate_wood_per_hour'],
            'rate_stone_per_hour' => (int)$s['rate_stone_per_hour'],
            'capacity_gold'       => (int)$s['capacity_gold'],
            'capacity_wood'       => (int)$s['capacity_wood'],
            'capacity_stone'      => (int)$s['capacity_stone'],
        ];
    }
}
