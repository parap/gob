<?php
declare(strict_types=1);

// Open-ended d6 (a 6 explodes: reroll and add). Long-tailed, like Dominions DRN.
function drn(): int
{
    $total = 0;
    do { $r = random_int(1, 6); $total += $r; } while ($r === 6);
    return $total;
}
function twoDRN(): int { return drn() + drn(); }

// Which monster races/tags fit each terrain.
const TERRAIN_POOLS = [
    'plains'    => ['human', 'goblin', 'animal', 'humanoid'],
    'forest'    => ['animal', 'beast', 'magic'],
    'mountains' => ['giant', 'construct', 'humanoid'],
    'swamp'     => ['undead', 'aquatic', 'animal', 'cold-blooded'],
    'city'      => ['human', 'humanoid', 'magic'],
    'caves'     => ['construct', 'magic', 'undead', 'demon'],
];
const TERRAINS = ['plains', 'forest', 'mountains', 'swamp', 'city', 'caves'];

// Map the settlement's terrain enum to a province terrain.
function settlementTerrainToProvince(string $t): string
{
    return in_array($t, TERRAINS, true) ? $t : 'plains';
}

function provinceName(string $terrain): string
{
    $adj  = ['Ash', 'Grey', 'Old', 'Black', 'Green', 'Iron', 'Misty', 'Wild', 'Cold', 'Silent', 'Broken', 'Red', 'Hollow', 'Far'];
    $noun = [
        'plains'    => ['Fields', 'Downs', 'Plain', 'Steppe', 'Meadows'],
        'forest'    => ['Forest', 'Woods', 'Grove', 'Thicket', 'Wildwood'],
        'mountains' => ['Peaks', 'Highlands', 'Crags', 'Pass', 'Spires'],
        'swamp'     => ['Marsh', 'Fen', 'Bog', 'Mire', 'Sloughs'],
        'city'      => ['City', 'Town', 'Keep', 'Market', 'Bastion'],
        'caves'     => ['Caves', 'Depths', 'Warren', 'Hollows', 'Undercroft'],
    ][$terrain];
    return $adj[array_rand($adj)] . ' ' . $noun[array_rand($noun)];
}

// Candidate monster ids for a terrain within a level band.
function terrainMonsterIds(string $terrain, int $level): array
{
    $pool = TERRAIN_POOLS[$terrain] ?? ['humanoid'];
    $ph   = implode(',', array_fill(0, count($pool), '?'));
    $lo   = max(1, $level - 2);
    $hi   = $level + 3;
    $stmt = db()->prepare(
        "SELECT DISTINCT m.id FROM monsters m
         LEFT JOIN monster_tags t ON t.monster_id = m.id
         WHERE (m.race IN ($ph) OR t.tag IN ($ph))
           AND m.level BETWEEN ? AND ?"
    );
    $stmt->execute([...$pool, ...$pool, $lo, $hi]);
    $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    if (!$ids) { // fallback: any monster in the band
        $stmt = db()->prepare('SELECT id FROM monsters WHERE level BETWEEN ? AND ?');
        $stmt->execute([$lo, $hi]);
        $ids = array_map('intval', array_column($stmt->fetchAll(), 'id'));
    }
    return $ids ?: [1];
}

function siteFlavorName(string $type, string $terrain): string
{
    $pools = [
        'minor'   => ['Old Camp', 'Ruined Shrine', 'Abandoned Hut', 'Hidden Cache', 'Wayside Ruin', 'Deserted Post'],
        'boon'    => ['Sacred Spring', 'Ley Nexus', 'Fertile Glade', 'Rich Vein', 'Blessed Grove', 'Standing Stones'],
        'dungeon' => ['Forgotten Crypt', 'Sunken Vault', 'Cursed Barrow', 'Dark Warren', 'Ancient Tomb', 'Lost Halls'],
    ];
    $p = $pools[$type] ?? $pools['minor'];
    return $p[array_rand($p)];
}

// Generate a province plus its scattered sites/dungeons/roads. Returns id.
function generateProvince(int $playerId, string $terrain, int $level, bool $isHome): int
{
    $db = db();
    $db->prepare('INSERT INTO provinces (player_id, name, terrain, level, is_home) VALUES (?, ?, ?, ?, ?)')
       ->execute([$playerId, provinceName($terrain), $terrain, $level, $isHome ? 1 : 0]);
    $provinceId = (int)$db->lastInsertId();

    $candidates = terrainMonsterIds($terrain, $level);
    $pick = fn() => $candidates[array_rand($candidates)];

    $insert = $db->prepare(
        'INSERT INTO province_sites
         (province_id, player_id, type, name, position, concealment, reward_gold,
          bonus_gold_rate, bonus_wood_rate, bonus_stone_rate, bonus_regen,
          reward_item_id, road_terrain, stages_json, description)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );

    $siteCount = random_int(30, 50);
    for ($i = 0; $i < $siteCount; $i++) {
        $roll = random_int(1, 100);
        $type = $roll <= 70 ? 'minor' : ($roll <= 90 ? 'boon' : 'dungeon');
        $pos  = random_int(0, 10000) / 100;

        $goldR = $woodR = $stoneR = $regen = 0;
        $rewardGold = 0;
        $rewardItem = null;

        if ($type === 'minor') {
            $conceal = random_int(1, 3);
            $stages  = [$pick()];
            if (random_int(0, 1)) $stages[] = $pick();
            $rewardGold = random_int(5, 20) * $level;
        } elseif ($type === 'boon') {
            $conceal = random_int(4, 9);
            $stages  = [$pick(), $pick()];
            if (random_int(0, 1)) $stages[] = $pick();
            // one small ongoing bonus
            switch (random_int(0, 3)) {
                case 0: $goldR  = random_int(3, 8); break;
                case 1: $woodR  = random_int(3, 8); break;
                case 2: $stoneR = random_int(3, 8); break;
                default: $regen = random_int(5, 15); break;
            }
        } else { // dungeon
            $conceal = random_int(8, 16);
            $n = random_int(3, 5);
            $stages = [];
            for ($s = 0; $s < $n; $s++) $stages[] = $pick();
            $rewardGold = random_int(30, 80) * $level;
            $rewardItem = randomItemId($conceal >= 12 ? 'rare' : 'uncommon');
        }

        $insert->execute([
            $provinceId, $playerId, $type, siteFlavorName($type, $terrain), $pos, $conceal,
            $rewardGold, $goldR, $woodR, $stoneR, $regen, $rewardItem, null,
            json_encode($stages), null,
        ]);
    }

    // A few roads to as-yet-ungenerated neighbours.
    $roads = random_int(2, 4);
    for ($i = 0; $i < $roads; $i++) {
        $insert->execute([
            $provinceId, $playerId, 'road',
            'Road to ' . ucfirst(TERRAINS[array_rand(TERRAINS)]),
            random_int(0, 10000) / 100, random_int(3, 8),
            0, 0, 0, 0, 0, null, TERRAINS[array_rand(TERRAINS)], null, null,
        ]);
    }

    return $provinceId;
}

// Pick a random item id at (roughly) the given rarity, for dungeon rewards.
function randomItemId(string $rarity): ?int
{
    $stmt = db()->prepare('SELECT id FROM items WHERE rarity = ? AND kind = "gear" ORDER BY RAND() LIMIT 1');
    $stmt->execute([$rarity]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

// Ensure the player has a home province (terrain from their settlement) and a
// current-province pointer. Returns the current province id.
function ensureHomeProvince(int $playerId, int $charId): int
{
    $db = db();
    $stmt = $db->prepare('SELECT current_province_id FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $cur = $stmt->fetchColumn();
    if ($cur) {
        return (int)$cur;
    }

    // Terrain of the home province = the player's settlement terrain.
    $stmt = $db->prepare('SELECT terrain FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
    $stmt->execute([$playerId]);
    $terrain = settlementTerrainToProvince((string)($stmt->fetchColumn() ?: 'plains'));

    $homeId = generateProvince($playerId, $terrain, 1, true);
    $db->prepare('UPDATE characters SET current_province_id = ? WHERE id = ?')->execute([$homeId, $charId]);
    return $homeId;
}

// Shape a discovered site row for the client (hidden sites are never sent).
function worldSitePayload(array $s): array
{
    $stages = $s['stages_json'] ? json_decode($s['stages_json'], true) : [];
    return [
        'id'           => (int)$s['id'],
        'type'         => $s['type'],
        'name'         => $s['name'],
        'state'        => $s['state'],
        'progress'     => (int)$s['progress'],
        'total_stages' => count($stages),
        'road_terrain' => $s['road_terrain'],
        'reward'       => [
            'gold'       => (int)$s['reward_gold'],
            'gold_rate'  => (int)$s['bonus_gold_rate'],
            'wood_rate'  => (int)$s['bonus_wood_rate'],
            'stone_rate' => (int)$s['bonus_stone_rate'],
            'regen'      => (int)$s['bonus_regen'],
            'item_id'    => $s['reward_item_id'] !== null ? (int)$s['reward_item_id'] : null,
        ],
    ];
}

function handleWorld(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $cur    = ensureHomeProvince((int)$player['id'], $charId);
    $db     = db();

    $stmt = $db->prepare('SELECT * FROM provinces WHERE player_id = ? ORDER BY id');
    $stmt->execute([$player['id']]);
    $provinces = array_map(fn(array $p) => [
        'id'           => (int)$p['id'],
        'name'         => $p['name'],
        'terrain'      => $p['terrain'],
        'level'        => (int)$p['level'],
        'is_home'      => (int)$p['is_home'] === 1,
        'is_current'   => (int)$p['id'] === $cur,
        'explored_pct' => (float)$p['explored_pct'],
    ], $stmt->fetchAll());

    // Only discovered (found/cleared) sites are revealed to the client.
    $stmt = $db->prepare('SELECT * FROM province_sites WHERE player_id = ? AND state <> "hidden" ORDER BY province_id, position');
    $stmt->execute([$player['id']]);
    $sites = [];
    foreach ($stmt->fetchAll() as $s) {
        $sites[(int)$s['province_id']][] = worldSitePayload($s);
    }

    json(200, [
        'current_province_id' => $cur,
        'provinces'           => $provinces,
        'sites'               => $sites,
    ]);
}
