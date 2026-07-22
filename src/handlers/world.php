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
        $rt = TERRAINS[array_rand(TERRAINS)];   // one terrain for both label and target
        $insert->execute([
            $provinceId, $playerId, 'road',
            'Road to ' . ucfirst($rt),
            random_int(0, 10000) / 100, random_int(3, 8),
            0, 0, 0, 0, 0, null, $rt, null, null,
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

    // Name of the monster guarding the next stage (for sites still to fight).
    $next = null;
    if ($s['state'] === 'found' && isset($stages[(int)$s['progress']])) {
        $st = db()->prepare('SELECT name FROM monsters WHERE id = ?');
        $st->execute([(int)$stages[(int)$s['progress']]]);
        $next = $st->fetchColumn() ?: null;
    }

    return [
        'id'           => (int)$s['id'],
        'type'         => $s['type'],
        'name'         => $s['name'],
        'state'        => $s['state'],
        'progress'     => (int)$s['progress'],
        'total_stages' => count($stages),
        'next_monster' => $next,
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

const EXPLORE_COOLDOWN = 8;   // seconds between explores
const RAID_CHANCE      = 20;  // percent chance of a raid per explore

// Dexterity decides how much ground a single explore covers (2–5%).
function sweepPercent(float $dex): float
{
    return round(min(5.0, 2.0 + $dex * 0.5), 2);
}

function linkProvinces(int $playerId, int $x, int $y): void
{
    db()->prepare('INSERT IGNORE INTO province_links (player_id, a, b) VALUES (?, ?, ?)')
        ->execute([$playerId, min($x, $y), max($x, $y)]);
}

function itemName(int $id): string
{
    $st = db()->prepare('SELECT name FROM items WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

function addGold(array $player, int $gold): void
{
    if ($gold <= 0) return;
    $db  = db();
    $sel = $db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
    $sel->execute([$player['id']]);
    if ($s = $sel->fetch()) {
        tickSettlement($s);
        $db->prepare('UPDATE settlements SET gold = LEAST(capacity_gold, gold + ?) WHERE id = ?')
           ->execute([$gold, $s['id']]);
    }
}

// Apply (sign +1) or revoke (sign -1) a boon site's ongoing bonuses.
function applyBoon(array $player, int $charId, array $s, int $sign): void
{
    $db = db();
    $gr = $sign * (int)$s['bonus_gold_rate'];
    $wr = $sign * (int)$s['bonus_wood_rate'];
    $sr = $sign * (int)$s['bonus_stone_rate'];
    $rg = $sign * (int)$s['bonus_regen'];

    if ($gr || $wr || $sr) {
        $sel = $db->prepare('SELECT * FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
        $sel->execute([$player['id']]);
        if ($st = $sel->fetch()) {
            tickSettlement($st);
            $db->prepare(
                'UPDATE settlements SET
                    rate_gold_per_hour  = GREATEST(0, rate_gold_per_hour  + ?),
                    rate_wood_per_hour  = GREATEST(0, rate_wood_per_hour  + ?),
                    rate_stone_per_hour = GREATEST(0, rate_stone_per_hour + ?)
                 WHERE id = ?'
            )->execute([$gr, $wr, $sr, $st['id']]);
        }
    }
    if ($rg) {
        tickCharacterRegen($charId);
        $db->prepare('UPDATE characters SET regen_bonus = GREATEST(0, regen_bonus + ?) WHERE id = ?')
           ->execute([$rg, $charId]);
    }
}

function applySiteReward(array $player, int $charId, array $s): array
{
    $summary = ['gold' => 0, 'rate' => null, 'regen' => 0, 'item' => null];
    if ((int)$s['reward_gold'] > 0) {
        addGold($player, (int)$s['reward_gold']);
        $summary['gold'] = (int)$s['reward_gold'];
    }
    applyBoon($player, $charId, $s, 1);
    if ((int)$s['bonus_gold_rate'] || (int)$s['bonus_wood_rate'] || (int)$s['bonus_stone_rate']) {
        $summary['rate'] = [
            'gold'  => (int)$s['bonus_gold_rate'],
            'wood'  => (int)$s['bonus_wood_rate'],
            'stone' => (int)$s['bonus_stone_rate'],
        ];
    }
    if ((int)$s['bonus_regen']) $summary['regen'] = (int)$s['bonus_regen'];
    if ($s['reward_item_id'] !== null) {
        grantItem($charId, (int)$s['reward_item_id']);
        $summary['item'] = itemName((int)$s['reward_item_id']);
    }
    return $summary;
}

// A raid: the boss of a still-hidden dungeon attacks. Lose → hero knocked out
// and a random held boon site is retaken (its bonus revoked).
function maybeRaid(array $player, int $charId): ?array
{
    if (random_int(1, 100) > RAID_CHANCE) return null;
    $db = db();
    $st = $db->prepare('SELECT * FROM province_sites WHERE player_id = ? AND type = "dungeon" AND state = "hidden" ORDER BY RAND() LIMIT 1');
    $st->execute([$player['id']]);
    $dun = $st->fetch();
    if (!$dun) return null;

    $stages = json_decode($dun['stages_json'], true) ?: [];
    if (!$stages) return null;
    // A raiding party is one of the dungeon's monsters (not always the boss).
    $ms = $db->prepare('SELECT * FROM monsters WHERE id = ?');
    $ms->execute([(int)$stages[array_rand($stages)]]);
    $boss = $ms->fetch();
    if (!$boss) return null;

    $res     = resolveFight($player, $charId, $boss);
    $lostSite = null;
    if ($res['outcome'] === 'loss') {
        $b = $db->prepare(
            'SELECT * FROM province_sites WHERE player_id = ? AND type = "boon" AND state = "cleared"
             AND (bonus_gold_rate>0 OR bonus_wood_rate>0 OR bonus_stone_rate>0 OR bonus_regen>0)
             ORDER BY RAND() LIMIT 1'
        );
        $b->execute([$player['id']]);
        if ($bs = $b->fetch()) {
            applyBoon($player, $charId, $bs, -1);
            $db->prepare('UPDATE province_sites SET state = "found", progress = 0 WHERE id = ?')->execute([$bs['id']]);
            $lostSite = $bs['name'];
        }
    }
    return ['monster' => $boss['name'], 'combat' => $res, 'lost_site' => $lostSite];
}

function handleProvinceExplore(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $cur    = ensureHomeProvince((int)$player['id'], $charId);
    $db     = db();

    $st = $db->prepare('SELECT last_explore_at FROM characters WHERE id = ?');
    $st->execute([$charId]);
    $last = $st->fetchColumn();
    if ($last) {
        $el = time() - strtotime($last);
        if ($el < EXPLORE_COOLDOWN) {
            json(429, ['error' => 'You must rest before exploring again.', 'retry_after' => EXPLORE_COOLDOWN - $el]);
        }
    }
    $db->prepare('UPDATE characters SET last_explore_at = NOW() WHERE id = ?')->execute([$charId]);

    $c    = loadCharacter($charId);
    $dex  = $c['stats_effective']['dex'];
    $perc = $c['substats_effective']['perception'];

    $pv = $db->prepare('SELECT * FROM provinces WHERE id = ? AND player_id = ?');
    $pv->execute([$cur, $player['id']]);
    $prov = $pv->fetch();
    $old  = (float)$prov['explored_pct'];
    $new  = min(100.0, $old + sweepPercent((float)$dex));
    $db->prepare('UPDATE provinces SET explored_pct = ? WHERE id = ?')->execute([$new, $cur]);

    // Detection: re-roll every still-hidden site whose position we've swept past.
    $sites = $db->prepare('SELECT * FROM province_sites WHERE province_id = ? AND state = "hidden" AND position <= ?');
    $sites->execute([$cur, $new]);
    $found = [];
    $newProvince = null;
    foreach ($sites->fetchAll() as $s) {
        if ($perc + twoDRN() < (int)$s['concealment'] + twoDRN()) {
            continue; // missed — stays concealed, re-rolled next time
        }
        if ($s['type'] === 'road') {
            $nid = generateProvince((int)$player['id'], settlementTerrainToProvince((string)$s['road_terrain']), (int)$prov['level'] + 1, false);
            linkProvinces((int)$player['id'], $cur, $nid);
            $db->prepare('UPDATE province_sites SET state = "cleared" WHERE id = ?')->execute([$s['id']]);
            $nm = $db->prepare('SELECT name, terrain FROM provinces WHERE id = ?');
            $nm->execute([$nid]);
            $newProvince = $nm->fetch();
            $found[] = ['type' => 'road', 'name' => $s['name']];
        } else {
            $db->prepare('UPDATE province_sites SET state = "found" WHERE id = ?')->execute([$s['id']]);
            $s['state'] = 'found';
            $found[] = worldSitePayload($s);
        }
    }

    $raid = maybeRaid($player, $charId);

    json(200, [
        'explored_pct'     => $new,
        'sweep'            => round($new - $old, 2),
        'found'            => $found,
        'new_province'     => $newProvince ? ['name' => $newProvince['name'], 'terrain' => $newProvince['terrain']] : null,
        'raid'             => $raid,
        'cooldown_seconds' => EXPLORE_COOLDOWN,
        'character'        => loadCharacter($charId),
    ]);
}

function handleTravel(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $pid    = (int)(body()['province_id'] ?? 0);

    $st = db()->prepare('SELECT id FROM provinces WHERE id = ? AND player_id = ?');
    $st->execute([$pid, $player['id']]);
    if (!$st->fetchColumn()) {
        json(404, ['error' => 'Province not found.']);
    }
    db()->prepare('UPDATE characters SET current_province_id = ? WHERE id = ?')->execute([$pid, $charId]);
    json(200, ['current_province_id' => $pid]);
}

function handleSiteAdvance(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $sid    = (int)(body()['site_id'] ?? 0);
    $db     = db();

    $st = $db->prepare('SELECT * FROM province_sites WHERE id = ? AND player_id = ?');
    $st->execute([$sid, $player['id']]);
    $site = $st->fetch();
    if (!$site) json(404, ['error' => 'Site not found.']);
    if ($site['type'] === 'road') json(400, ['error' => 'Roads open on their own.']);
    if ($site['state'] !== 'found') json(400, ['error' => 'Nothing to fight here.']);

    $stages  = json_decode($site['stages_json'], true) ?: [];
    $total   = count($stages);
    $stageNo = (int)$site['progress'];
    if ($stageNo >= $total) json(400, ['error' => 'Already cleared.']);

    $ms = $db->prepare('SELECT * FROM monsters WHERE id = ?');
    $ms->execute([(int)$stages[$stageNo]]);
    $monster = $ms->fetch();

    $combat     = resolveFight($player, $charId, $monster);
    $cleared    = false;
    $completion = null;

    if ($combat['outcome'] === 'win') {
        $prog = $stageNo + 1;
        if ($prog >= $total) {
            $db->prepare('UPDATE province_sites SET progress = ?, state = "cleared" WHERE id = ?')->execute([$prog, $sid]);
            $completion = applySiteReward($player, $charId, $site);
            $cleared = true;
            $site['state'] = 'cleared';
        } else {
            $db->prepare('UPDATE province_sites SET progress = ? WHERE id = ?')->execute([$prog, $sid]);
        }
        $site['progress'] = $prog;
    }

    json(200, [
        'combat'      => $combat,
        'stage'       => $stageNo + 1,
        'total_stages'=> $total,
        'cleared'     => $cleared,
        'completion'  => $completion,
        'site'        => worldSitePayload($site),
        'character'   => loadCharacter($charId),
    ]);
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
