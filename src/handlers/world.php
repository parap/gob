<?php
declare(strict_types=1);

use Gob\Domain\Province;
use Gob\Domain\Site;
use Gob\Repository\WorldRepository;

// The province world. DB access lives in Gob\Repository\WorldRepository (and the
// other repos for monsters/items/settlements/characters); this file keeps the
// generation algorithm and request orchestration. ensureHomeProvince() and
// addGold() stay callable from village.php.

function worldRepo(): WorldRepository
{
    return \Gob\Repositories::get(WorldRepository::class);
}

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

const EXPLORE_COOLDOWN = 1;   // seconds; anti-spam floor (client search animation paces it)
const RAID_CHANCE      = 20;  // percent chance of a raid per explore

// Loot chance (%) and rarity for clearing each site type.
const SITE_LOOT = [
    'minor'   => [40, 'common'],
    'boon'    => [60, 'uncommon'],
    'dungeon' => [80, 'rare'],
];

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

// Dexterity decides how much ground a single explore covers (2–5%).
function sweepPercent(float $dex): float
{
    return round(min(5.0, 2.0 + $dex * 0.5), 2);
}

// Generate a province plus its scattered sites/dungeons/roads. Returns its id.
function generateProvince(int $playerId, string $terrain, int $level, bool $isHome): int
{
    $world      = worldRepo();
    $provinceId = $world->createProvince($playerId, provinceName($terrain), $terrain, $level, $isHome);

    $pool       = TERRAIN_POOLS[$terrain] ?? ['humanoid'];
    $candidates = monsterRepo()->terrainCandidates($pool, $level);
    $pick       = fn() => $candidates[array_rand($candidates)];

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
            $rewardItem = itemRepo()->randomByRarity($conceal >= 12 ? 'rare' : 'uncommon');
        }

        $world->insertSite([
            'province_id'      => $provinceId,
            'player_id'        => $playerId,
            'type'             => $type,
            'name'             => siteFlavorName($type, $terrain),
            'position'         => $pos,
            'concealment'      => $conceal,
            'reward_gold'      => $rewardGold,
            'bonus_gold_rate'  => $goldR,
            'bonus_wood_rate'  => $woodR,
            'bonus_stone_rate' => $stoneR,
            'bonus_regen'      => $regen,
            'reward_item_id'   => $rewardItem,
            'stages_json'      => json_encode($stages),
        ]);
    }

    // A few roads to as-yet-ungenerated neighbours.
    $roads = random_int(2, 4);
    for ($i = 0; $i < $roads; $i++) {
        $rt = TERRAINS[array_rand(TERRAINS)];   // one terrain for both label and target
        $world->insertSite([
            'province_id'  => $provinceId,
            'player_id'    => $playerId,
            'type'         => 'road',
            'name'         => 'Road to ' . ucfirst($rt),
            'position'     => random_int(0, 10000) / 100,
            'concealment'  => random_int(3, 8),
            'road_terrain' => $rt,
        ]);
    }

    return $provinceId;
}

// Ensure the player has a home province (terrain from their settlement) and a
// current-province pointer. Returns the current province id.
function ensureHomeProvince(int $playerId, int $charId): int
{
    $cur = worldRepo()->currentProvinceId($charId);
    if ($cur) {
        return $cur;
    }
    $terrain = settlementTerrainToProvince(settlementRepo()->homeTerrain($playerId) ?? 'plains');
    $homeId  = generateProvince($playerId, $terrain, 1, true);
    worldRepo()->setCurrentProvince($charId, $homeId);
    return $homeId;
}

// Shape a discovered site row for the client (hidden sites are never sent).
function worldSitePayload(array $s): array
{
    $site = new Site($s);
    $next = null;
    $mid  = $site->nextMonsterId();
    if ($mid !== null && ($m = monsterRepo()->find($mid))) {
        $next = ['name' => $m['name'], 'description' => $m['description']];
    }
    return $site->toArray($next);
}

// Credit gold to the player's settlement. Kept callable from village.php.
function addGold(array $player, int $gold): void
{
    settlementRepo()->addGold((int)$player['id'], $gold);
}

// Apply (sign +1) or revoke (sign -1) a boon site's ongoing bonuses.
function applyBoon(array $player, int $charId, array $s, int $sign): void
{
    settlementRepo()->adjustRates(
        (int)$player['id'],
        $sign * (int)$s['bonus_gold_rate'],
        $sign * (int)$s['bonus_wood_rate'],
        $sign * (int)$s['bonus_stone_rate'],
    );
    characterRepo()->adjustRegenBonus($charId, $sign * (int)$s['bonus_regen']);
}

function applySiteReward(array $player, int $charId, array $s): array
{
    $summary = ['gold' => 0, 'rate' => null, 'regen' => 0, 'items' => []];
    $items   = itemRepo();

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

    // Fixed reward item (dungeons carry one from generation).
    if ($s['reward_item_id'] !== null) {
        grantItem($charId, (int)$s['reward_item_id']);
        $summary['items'][] = $items->name((int)$s['reward_item_id']);
    }
    // Chance-based loot for clearing the site.
    if ($loot = SITE_LOOT[$s['type']] ?? null) {
        if (random_int(1, 100) <= $loot[0] && ($id = $items->randomByRarity($loot[1]))) {
            grantItem($charId, $id);
            $summary['items'][] = $items->name($id);
        }
    }
    // Dungeons have a shot at an epic on top.
    if ($s['type'] === 'dungeon' && random_int(1, 100) <= 25 && ($id = $items->randomByRarity('epic'))) {
        grantItem($charId, $id);
        $summary['items'][] = $items->name($id);
    }
    return $summary;
}

// A raid: the boss of a still-hidden dungeon attacks. Lose → hero knocked out
// and a random held boon site is retaken (its bonus revoked).
function maybeRaid(array $player, int $charId): ?array
{
    if (random_int(1, 100) > RAID_CHANCE) return null;
    $world = worldRepo();

    $dun = $world->randomHiddenDungeon((int)$player['id']);
    if (!$dun) return null;

    $stages = json_decode($dun['stages_json'], true) ?: [];
    if (!$stages) return null;

    // A raiding party is one of the dungeon's monsters (not always the boss).
    $boss = monsterRepo()->find((int)$stages[array_rand($stages)]);
    if (!$boss) return null;

    $res      = resolveFight($player, $charId, $boss);
    $lostSite = null;
    if ($res['outcome'] === 'loss') {
        if ($bs = $world->randomClearedBoon((int)$player['id'])) {
            applyBoon($player, $charId, $bs, -1);
            $world->setSiteProgressState((int)$bs['id'], 0, 'found');
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
    $world  = worldRepo();

    $remain = characterRepo()->exploreCooldownRemaining($charId, EXPLORE_COOLDOWN);
    if ($remain > 0) {
        json(429, ['error' => 'You must rest before exploring again.', 'retry_after' => $remain]);
    }
    characterRepo()->stampExplore($charId);

    $c    = loadCharacter($charId);
    $dex  = $c['stats_effective']['dex'];
    $perc = $c['substats_effective']['perception'];

    $prov = $world->findProvince($cur, (int)$player['id']);
    $old  = (float)$prov['explored_pct'];
    $new  = min(100.0, $old + sweepPercent((float)$dex));
    $world->updateExplored($cur, $new);

    // Detection: re-roll every still-hidden site whose position we've swept past.
    $found = [];
    $newProvince = null;
    foreach ($world->hiddenSweptSites($cur, $new) as $s) {
        if ($perc + twoDRN() < (int)$s['concealment'] + twoDRN()) {
            continue; // missed — stays concealed, re-rolled next time
        }
        if ($s['type'] === 'road') {
            $nid = generateProvince((int)$player['id'], settlementTerrainToProvince((string)$s['road_terrain']), (int)$prov['level'] + 1, false);
            $world->link((int)$player['id'], $cur, $nid);
            $world->setSiteState((int)$s['id'], 'cleared');
            $newProvince = $world->provinceBrief($nid);
            $found[] = ['type' => 'road', 'name' => $s['name'], 'at' => (int)round((float)$s['position'])];
        } else {
            $world->setSiteState((int)$s['id'], 'found');
            $s['state'] = 'found';
            $sp = worldSitePayload($s);
            $sp['at'] = (int)round((float)$s['position']);   // where on the 0-100 sweep it sits
            $found[] = $sp;
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

    if (!worldRepo()->findProvince($pid, (int)$player['id'])) {
        json(404, ['error' => 'Province not found.']);
    }
    worldRepo()->setCurrentProvince($charId, $pid);
    json(200, ['current_province_id' => $pid]);
}

function handleSiteAdvance(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $sid    = (int)(body()['site_id'] ?? 0);
    $world  = worldRepo();

    $site = $world->findSite($sid, (int)$player['id']);
    if (!$site) json(404, ['error' => 'Site not found.']);
    if ($site['type'] === 'road') json(400, ['error' => 'Roads open on their own.']);
    if ($site['state'] !== 'found') json(400, ['error' => 'Nothing to fight here.']);

    $stages  = json_decode($site['stages_json'], true) ?: [];
    $total   = count($stages);
    $stageNo = (int)$site['progress'];
    if ($stageNo >= $total) json(400, ['error' => 'Already cleared.']);

    $monster    = monsterRepo()->find((int)$stages[$stageNo]);
    $combat     = resolveFight($player, $charId, $monster);
    $cleared    = false;
    $completion = null;

    if ($combat['outcome'] === 'win') {
        $prog = $stageNo + 1;
        if ($prog >= $total) {
            $world->setSiteProgressState($sid, $prog, 'cleared');
            $completion = applySiteReward($player, $charId, $site);
            $cleared = true;
            $site['state'] = 'cleared';
        } else {
            $world->setSiteProgress($sid, $prog);
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
    $world  = worldRepo();

    $provinces = array_map(
        fn(array $p) => (new Province($p, (int)$p['id'] === $cur))->toArray(),
        $world->provincesForPlayer((int)$player['id'])
    );

    // Only discovered (found/cleared) sites are revealed to the client.
    $sites = [];
    foreach ($world->visibleSites((int)$player['id']) as $s) {
        $sites[(int)$s['province_id']][] = worldSitePayload($s);
    }

    json(200, [
        'current_province_id' => $cur,
        'provinces'           => $provinces,
        'sites'               => $sites,
    ]);
}
