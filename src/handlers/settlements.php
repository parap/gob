<?php
declare(strict_types=1);

use Gob\Domain\Settlement;
use Gob\Repository\SettlementRepository;

// Settlement logic now lives in Gob\Domain\Settlement and
// Gob\Repository\SettlementRepository. These procedural wrappers keep the
// existing call sites (auth, items, explore, world) working during migration.

function settlementRepo(): SettlementRepository
{
    return \Gob\Repositories::get(SettlementRepository::class);
}

function createStartingSettlement(int $playerId): void
{
    settlementRepo()->createStarting($playerId);
}

// Apply accrued production, persist, return the updated row.
function tickSettlement(array $s): array
{
    return settlementRepo()->tick($s);
}

// Shape a settlement row into the JSON the frontend expects.
function settlementPayload(array $s): array
{
    return (new Settlement($s))->toArray();
}

function handleMySettlements(): void
{
    $player = requirePlayer();
    $repo   = settlementRepo();
    $out    = array_map(
        fn(array $s) => (new Settlement($repo->tick($s)))->toArray(),
        $repo->forPlayer((int)$player['id'])
    );
    json(200, $out);
}
