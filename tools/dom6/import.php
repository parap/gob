<?php
declare(strict_types=1);
/*
 * Dominions 6 Inspector -> GOB importer.
 *
 * Reads the CSVs in tools/dom6/gamedata/ (kept in the repo), fetches per-entity
 * descriptions from the live inspector site, maps a curated, balanced subset to
 * our `monsters` and `items` tables, and upserts them.
 *
 *   Imported monsters use ids >= 1000, items >= 2000 (never clashes with seeds).
 *   Re-runnable (upsert). Source content is Dominions 6 (Illwinter) data — see
 *   the note in README before publishing.
 *
 * Usage:  php tools/dom6/import.php
 * DB conn via env (defaults target the docker-mapped MySQL): DB_HOST=127.0.0.1
 *   DB_PORT=3307 DB_NAME=gob DB_USER=gob DB_PASS=gobdevpass
 */

$DIR  = __DIR__ . '/gamedata';
$SITE = 'https://larzm42.github.io/dom6inspector/gamedata';

$MONSTER_COUNT = 40;
$ITEMS_PER_SLOT = 5;

// ---------- helpers ----------
function loadTsv(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $head  = explode("\t", array_shift($lines));
    $rows  = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        $cells = explode("\t", $line);
        $row = [];
        foreach ($head as $i => $col) $row[$col] = $cells[$i] ?? '';
        $rows[] = $row;
    }
    return $rows;
}

function fetchText(string $url): string
{
    $txt = @file_get_contents($url);
    return $txt === false ? '' : trim($txt);
}

function unitDescr(string $site, string $id): string
{
    return fetchText($site . '/unitdescr/' . str_pad($id, 4, '0', STR_PAD_LEFT) . '.txt');
}

function itemDescr(string $site, string $name): string
{
    $file = preg_replace('/[^a-zA-Z0-9\-]/', '', $name) . '.txt';
    return fetchText($site . '/itemdescr/' . $file);
}

function clampi($v, int $lo, int $hi): int { return max($lo, min($hi, (int)round((float)$v))); }
function num($v): int { return is_numeric($v) ? (int)$v : 0; }

// Swap distinctive Dominions/Illwinter proper nouns (in names OR descriptions)
// for plain fantasy terms, leaving ordinary words intact. Covers the prominent
// terms; a few obscure proper names may remain.
function genericize(string $text): string
{
    // Multi-word phrases first.
    $phrases = [
        "Lakam Ha'"             => 'Jungle',
        'Turan Usij'            => 'Ashen Cultist',
        'Closed Council'        => 'Grand Council',
        'Teteo Inan'            => 'the Mother Goddess',
        'Celestial Bureaucracy' => 'the Imperial Court',
        'Celestial Empire'      => 'the Eastern Empire',
    ];
    foreach ($phrases as $from => $to) $text = str_replace($from, $to, $text);

    // Whole-word substitutions (plurals listed before singulars).
    $words = [
        // terms that also appear in the renamed monster names
        'Sepulchre' => 'Tomb',          'Woodhenge' => 'Wildwood',
        'Anansis'   => 'Spider Lords',  'Anansi'    => 'Spider Lord',
        'Limitane'  => 'Legion',        'Erytheian' => 'Coastal',
        'Humanbreds'=> 'Beastborn',     'Humanbred' => 'Beastborn',
        'Nemedian'  => 'Highland',      'Naginis'   => 'Serpent Maidens',
        'Nagini'    => 'Serpent Maiden','Fianna'    => 'Clan Warriors',
        'Aphroi'    => 'Tide',          'Gileadite' => 'Zealot',
        'Teotls'    => 'Gods',          'Teotl'     => 'God',
        'Neters'    => 'Gods',          'Neter'     => 'God',
        // lore proper nouns that show up in descriptions
        'Pantokrator' => 'Almighty',    'Eldregate' => 'the cathedral city',
        'Abysian'     => 'ashland',     'Abysia'    => 'the Ashlands',
        'Agartha'     => 'the deep caves', 'Jotunheim' => 'the frozen north',
        'Atlantians'  => 'deep folk',   'Asphodel'  => 'the deadwood',
        'Avalon'      => 'the Isles',   'Rephaim'   => 'giants',
        'Xibalba'     => 'the underworld', 'Mictlan' => 'the Sun Kingdom',
        'Pythium'     => 'the Empire',  'Ermor'     => 'the old Empire',
        'Marignon'    => 'the Kingdom', 'Arcoscephale' => 'the old Kingdom',
        'Pangaea'     => 'the Wildlands', 'Erytheia' => 'the Coast',
        'Gilead'      => 'the highlands', 'Machaka'  => 'the Spider Kingdom',
        "R'lyeh"      => 'the Sunken City', "Rl'yeh"  => 'the Sunken City',
        'Pelagia'     => 'the Sea Kingdom', 'Oceania' => 'the Ocean Realm',
        'Bureaucracy' => 'Court',          'Ulm'     => 'the Iron Lands',
        'Vanir'       => 'northmen',        'Patala'  => 'the Serpent Realm',
        'Eriu'        => 'the Green Isle',  'Fomoria' => 'the Drowned Isles',
        'Muuch'       => 'the Toad Lord',   'Nin'     => 'Temple Warden',
        'Ind'         => 'the eastern lands', 'Ur'    => 'the First City',
    ];
    foreach ($words as $from => $to) {
        $text = preg_replace('/\b' . preg_quote($from, '/') . '\b/', $to, $text);
    }
    return $text;
}

// Monster display name: a hand-picked creative name where one fits, else the
// generic substitution above.
function monsterName(string $orig): string
{
    static $overrides = [
        'Knight of the Unholy Sepulchre' => 'Knight of the Unhallowed Tomb',
        'Turan Usij'                     => 'Ashen Cultist',
        'Humanbred'                      => 'Beastborn',
        'Nin'                            => 'Temple Warden',
        'Teotl of War'                   => 'War Idol',
        'Neter of the Sun'               => 'Solar Godling',
        'Leader of the Closed Council'   => 'High Councilor',
        'Aphroi Lord'                    => 'Tide Lord',
        'Olm Sage'                       => 'Deepcave Sage',
        'Nagini'                         => 'Serpent Maiden',
    ];
    return $overrides[$orig] ?? genericize($orig);
}

// Derive [race, alignment, tags[]] from Dominions boolean flag columns.
function classifyMonster(array $u): array
{
    $flag = fn(string $k) => trim($u[$k] ?? '') === '1';
    $size = num($u['size'] ?? '');

    $undead = $flag('undead');
    $demon  = $flag('demon');
    $inan   = $flag('inanimate') || $flag('stonebeing');
    $magic  = $flag('magicbeing');
    $animal = $flag('animal');
    $holy   = $flag('holy');

    if ($undead)          $race = 'undead';
    elseif ($demon)       $race = 'demon';
    elseif ($inan)        $race = 'construct';
    elseif ($animal)      $race = 'animal';
    elseif ($magic)       $race = 'magical';
    elseif ($size >= 5)   $race = 'giant';
    else                  $race = 'humanoid';

    if ($undead || $demon) $align = 'evil';
    elseif ($holy)         $align = 'good';
    else                   $align = 'neutral';

    $tags = [];
    if ($undead) $tags[] = 'undead';
    if ($demon)  $tags[] = 'demon';
    if ($inan)   $tags[] = 'inanimate';
    if ($magic)  $tags[] = 'magic';
    if ($animal) { $tags[] = 'animal'; $tags[] = 'beast'; }
    if ($holy)   $tags[] = 'holy';
    if ($flag('coldblood')) $tags[] = 'cold-blooded';
    if ($flag('female'))    $tags[] = 'female';
    if ($flag('flying'))    $tags[] = 'flying';
    if ($flag('aquatic') || $flag('amphibian')) $tags[] = 'aquatic';
    if ($flag('mounted'))   $tags[] = 'mounted';
    if ($size >= 5)         $tags[] = 'giant';
    if (!$undead && !$demon && !$inan && !$magic && !$animal) $tags[] = 'humanoid';
    $tags[] = $align;

    return [$race, $align, array_values(array_unique($tags))];
}

// ---------- monsters ----------
function buildMonsters(array $units, string $site, int $count): array
{
    $seen = [];
    $pool = [];
    foreach ($units as $u) {
        $name = trim($u['name']);
        $hp   = num($u['hp']);
        if ($name === '' || preg_match('/\d/', $name)) continue;   // skip numbered variants
        if ($hp < 6 || $hp > 120) continue;                        // sane, human-ish scale
        if (isset($seen[$name])) continue;
        $seen[$name] = true;
        $pool[] = $u;
    }
    usort($pool, fn($a, $b) => num($a['hp']) <=> num($b['hp']));

    // Sample evenly across the difficulty (hp) range.
    $picked = [];
    $n = count($pool);
    if ($n === 0) return [];
    for ($i = 0; $i < $count; $i++) {
        $picked[] = $pool[(int)floor($i * ($n - 1) / max(1, $count - 1))];
    }
    $picked = array_values(array_unique($picked, SORT_REGULAR));

    $out = [];
    $id  = 1000;
    foreach ($picked as $u) {
        $hp   = clampi(num($u['hp']) * 2.5, 10, 600);       // scale to our combat
        $att  = clampi(num($u['att']), 1, 40);
        $def  = clampi(num($u['def']) / 3, 0, 12);
        $prot = clampi(num($u['prot']) / 3, 0, 12);
        $pen  = clampi(num($u['str']) / 5, 0, 8);
        // Dominions uses basecost >= 1000 as a "not normally recruited" sentinel;
        // fall back to an hp-based reward in that case.
        $bc   = num($u['basecost']);
        $gold = ($bc >= 1 && $bc < 1000) ? $bc * 2 : (int)round($hp / 3);
        $gold = clampi($gold, 5, 500);
        [$race, $align, $tags] = classifyMonster($u);
        $out[] = [
            'id'          => $id++,
            'name'        => monsterName($u['name']),
            'level'       => clampi($hp / 15, 1, 25),
            'hp'          => $hp,
            'attack'      => $att,
            'defense'     => $def,
            'protection'  => $prot,
            'penetration' => $pen,
            'reward_gold' => $gold,
            'loot_item_id'=> null,
            'loot_chance' => 0,
            'race'        => $race,
            'alignment'   => $align,
            'description' => genericize(unitDescr($site, $u['id'])),
            'tags'        => $tags,   // stripped before upsert; inserted into monster_tags
        ];
    }
    return $out;
}

// ---------- items ----------
function rarityFromLevel(int $lvl): string
{
    if ($lvl <= 3) return 'common';
    if ($lvl <= 7) return 'uncommon';
    if ($lvl <= 11) return 'rare';
    return 'epic';
}
function sellByRarity(string $r): int
{
    return ['common' => 10, 'uncommon' => 40, 'rare' => 120, 'epic' => 300][$r] ?? 10;
}
function weaponSkillFromName(string $name): string
{
    $n = strtolower($name);
    if (str_contains($n, 'bow') || str_contains($n, 'sling') || str_contains($n, 'crossbow')) return 'bow';
    if (str_contains($n, 'axe')) return 'axe';
    if (str_contains($n, 'flail') || str_contains($n, 'mace') || str_contains($n, 'morning') || str_contains($n, 'hammer')) return 'flail';
    return 'sword';
}
// Dominions item `type` -> our slot_type (null = skip).
function slotFromType(string $type, int $id): ?string
{
    switch ($type) {
        case '1-h wpn': case '2-h wpn': case 'missile': return 'weapon';
        case 'armor':   return 'platemail';
        case 'shield':  return 'shield';
        case 'helm':    return 'head';
        case 'crown':   return 'head';
        case 'boots':   return 'foot';
        case 'misc':    return ['ring', 'bracelet', 'glasses', 'banner'][$id % 4];
        default:        return null;   // barding etc.
    }
}

function buildItems(array $items, array $weaponsById, array $armorsById, string $site, int $perSlot): array
{
    $protByRarity = ['common' => 4, 'uncommon' => 8, 'rare' => 14, 'epic' => 20];
    $atkByRarity  = ['common' => 4, 'uncommon' => 6, 'rare' => 9,  'epic' => 13];
    $penByRarity  = ['common' => 1, 'uncommon' => 2, 'rare' => 3,  'epic' => 4];

    $mapped = [];
    foreach ($items as $it) {
        $slot = slotFromType($it['type'] ?? '', num($it['id']));
        if ($slot === null) continue;
        $name = trim($it['name']);
        if ($name === '') continue;

        $rarity = rarityFromLevel(num($it['constlevel']));

        // Own magic-bonus columns.
        $b = [
            'str' => clampi($it['str'] ?? 0, -5, 12),
            'attack' => clampi($it['att'] ?? 0, 0, 15),
            'defense' => clampi($it['def'] ?? 0, 0, 15),
            'protection' => clampi($it['protf'] ?? 0, 0, 15),
            'hp' => clampi($it['hp'] ?? 0, 0, 80),
            'perception' => clampi($it['prec'] ?? 0, 0, 10),
            'courage' => clampi($it['morale'] ?? 0, 0, 30),
        ];

        $weaponSkill = null;
        if ($slot === 'weapon') {
            $weaponSkill = weaponSkillFromName($name);
            $wid = $it['weapon'] ?? '';
            if ($wid !== '' && isset($weaponsById[$wid])) {
                $b['attack'] += clampi($weaponsById[$wid]['att'] ?? 0, 0, 6);
            }
            $b['attack'] += $atkByRarity[$rarity];
            $b['penetration'] = ($b['penetration'] ?? 0) + $penByRarity[$rarity];
        } elseif (in_array($slot, ['platemail', 'shield', 'head', 'foot'], true)) {
            $aid = $it['armor'] ?? '';
            if ($aid !== '' && isset($armorsById[$aid])) {
                $b['defense'] += clampi($armorsById[$aid]['def'] ?? 0, 0, 8);
            }
            $frac = $slot === 'platemail' ? 1.0 : 0.5;
            $b['protection'] = ($b['protection'] ?? 0) + (int)round($protByRarity[$rarity] * $frac);
        } elseif (in_array($slot, ['ring', 'bracelet', 'glasses', 'banner'], true)) {
            // Exotic accessories carry their power in effect columns we don't map;
            // give a small slot-appropriate bonus so they're not inert.
            if (array_sum(array_map('intval', $b)) === 0) {
                $tier = ['common' => 1, 'uncommon' => 2, 'rare' => 3, 'epic' => 4][$rarity];
                if ($slot === 'glasses')      $b['perception'] = $tier;
                elseif ($slot === 'banner')   { $b['courage'] = $tier * 5; $b['attack'] = $tier; }
                elseif ($slot === 'ring')     $b['str'] = $tier;
                else                          $b['defense'] = $tier; // bracelet
            }
        }

        $mapped[] = [
            'slot' => $slot, 'rarity' => $rarity, 'name' => $name, 'raw' => $it,
            'weapon_skill' => $weaponSkill, 'bonuses' => $b,
        ];
    }

    // Curate: up to $perSlot per slot type, spread across the list for variety.
    $bySlot = [];
    foreach ($mapped as $m) $bySlot[$m['slot']][] = $m;
    $picked = [];
    foreach ($bySlot as $group) {
        $g = count($group);
        $take = min($perSlot, $g);
        for ($i = 0; $i < $take; $i++) $picked[] = $group[(int)floor($i * ($g - 1) / max(1, $take - 1))];
    }

    $out = [];
    $id  = 2000;
    foreach ($picked as $m) {
        $bz = $m['bonuses'];
        $out[] = [
            'id'                => $id++,
            'name'              => genericize($m['name']),
            'slot_type'         => $m['slot'],
            'weapon_skill'      => $m['weapon_skill'],
            'rarity'            => $m['rarity'],
            'bonus_str'         => (int)($bz['str'] ?? 0),
            'bonus_dex'         => 0,
            'bonus_con'         => 0,
            'bonus_int'         => 0,
            'bonus_wis'         => 0,
            'bonus_cha'         => 0,
            'bonus_hp'          => (int)($bz['hp'] ?? 0),
            'bonus_mana'        => 0,
            'bonus_courage'     => (int)($bz['courage'] ?? 0),
            'bonus_defense'     => (int)($bz['defense'] ?? 0),
            'bonus_protection'  => (int)($bz['protection'] ?? 0),
            'bonus_attack'      => (int)($bz['attack'] ?? 0),
            'bonus_penetration' => (int)($bz['penetration'] ?? 0),
            'bonus_perception'  => (int)($bz['perception'] ?? 0),
            'kind'              => 'gear',
            'heal_hp'           => 0,
            'sell_value'        => sellByRarity($m['rarity']),
            'description'       => genericize(itemDescr($site, $m['name'])),
        ];
    }
    return $out;
}

// ---------- upsert ----------
function upsert(PDO $db, string $table, array $rows): int
{
    if (!$rows) return 0;
    $cols = array_keys($rows[0]);
    $ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $set  = implode(',', array_map(fn($c) => "$c=VALUES($c)", $cols));
    $sql  = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES $ph
             ON DUPLICATE KEY UPDATE $set";
    $stmt = $db->prepare($sql);
    foreach ($rows as $r) $stmt->execute(array_values($r));
    return count($rows);
}

// ---------- run ----------
$db = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3307', getenv('DB_NAME') ?: 'gob'),
    getenv('DB_USER') ?: 'gob', getenv('DB_PASS') ?: 'gobdevpass',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

fwrite(STDERR, "Loading CSVs...\n");
$units   = loadTsv("$DIR/BaseU.csv");
$items   = loadTsv("$DIR/BaseI.csv");
$weapons = [];
foreach (loadTsv("$DIR/weapons.csv") as $w) $weapons[$w['id']] = $w;
$armors  = [];
foreach (loadTsv("$DIR/armors.csv") as $a) $armors[$a['id']] = $a;

fwrite(STDERR, "Building + fetching monster descriptions...\n");
$monsters = buildMonsters($units, $SITE, $MONSTER_COUNT);
fwrite(STDERR, "Building + fetching item descriptions...\n");
$gear = buildItems($items, $weapons, $armors, $SITE, $ITEMS_PER_SLOT);

// Pull tags out of the monster rows (they go into their own table).
$tagRows = [];
foreach ($monsters as &$mon) {
    foreach ($mon['tags'] as $t) $tagRows[] = ['monster_id' => $mon['id'], 'tag' => $t];
    unset($mon['tags']);
}
unset($mon);

$db->beginTransaction();
$m = upsert($db, 'monsters', $monsters);
$i = upsert($db, 'items', $gear);
// Refresh imported monsters' tags.
$db->exec('DELETE FROM monster_tags WHERE monster_id >= 1000');
$tagStmt = $db->prepare('INSERT IGNORE INTO monster_tags (monster_id, tag) VALUES (?, ?)');
foreach ($tagRows as $t) $tagStmt->execute([$t['monster_id'], $t['tag']]);
$db->commit();

fwrite(STDERR, "Imported $m monsters, $i items, " . count($tagRows) . " tags.\n");
