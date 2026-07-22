<?php
declare(strict_types=1);

// Give a new player their starting settlement. Defaults (resources, rates,
// caps) come from the schema, so we only set what differs per settlement.
function createStartingSettlement(int $playerId): void
{
    $stmt = db()->prepare(
        'INSERT INTO settlements (player_id, name, terrain) VALUES (?, ?, ?)'
    );
    $stmt->execute([$playerId, 'Capital', 'plains']);
}

// Apply production accrued since last_tick, persist it, and return the
// updated row. This is the server-side half of the "rates + timestamps"
// model — resources catch up whenever the settlement is read.
function tickSettlement(array $s): array
{
    $nowTs  = time();
    $lastTs = strtotime($s['last_tick']);
    $elapsedHours = ($nowTs - $lastTs) / 3600;

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

    $stmt = db()->prepare(
        'UPDATE settlements SET gold = ?, wood = ?, stone = ?, last_tick = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$s['gold'], $s['wood'], $s['stone'], $s['id']]);

    return $s;
}

// Shape a settlement row into the JSON the frontend expects (ints, no secrets).
function settlementPayload(array $s): array
{
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

function handleMySettlements(): void
{
    $player = requirePlayer();

    $stmt = db()->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id');
    $stmt->execute([$player['id']]);

    $settlements = array_map(
        fn(array $s) => settlementPayload(tickSettlement($s)),
        $stmt->fetchAll()
    );

    json(200, $settlements);
}
