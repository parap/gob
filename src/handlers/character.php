<?php
declare(strict_types=1);

// Skills every new character starts with (value 1). Extend freely — these
// are plain strings, so adding a weapon needs no database migration.
// 'attack' lets the hero strike with their equipped weapon.
const CHARACTER_SKILLS = ['attack', 'unarmed', 'sword', 'axe', 'bow', 'flail'];

// Combat sub-stats: separate group from the six primary stats.
const SUBSTAT_KEYS = ['defense', 'protection', 'attack', 'penetration'];

// Passive HP regeneration rate.
const HP_REGEN_PER_MIN = 60;

// Equipment slots the paperdoll exposes. Two rings and two bracelets get
// numbered slots; the rest are unique.
const EQUIPMENT_SLOTS = [
    'ring_1', 'ring_2',
    'bracelet_1', 'bracelet_2',
    'platemail', 'gauntlets', 'sleeves', 'pants', 'foot',
    'head', 'glasses',
    'weapon', 'shield', 'banner',
];

// Item ids (from the schema seed) every new character starts with.
const STARTER_ITEMS = [1, 3]; // Rusty Sword, Leather Cap

// Maps DB stat columns → the short keys the API/frontend use.
const STAT_KEYS = [
    'strength'     => 'str',
    'dexterity'    => 'dex',
    'constitution' => 'con',
    'intelligence' => 'int',
    'wisdom'       => 'wis',
    'charisma'     => 'cha',
];

// Create the character (plus its skills and empty equipment slots) if the
// player doesn't have one yet. Safe to call repeatedly. Returns character id.
function ensureCharacter(int $playerId, string $name): int
{
    $db = db();

    $stmt = $db->prepare('SELECT id FROM characters WHERE player_id = ?');
    $stmt->execute([$playerId]);
    if ($row = $stmt->fetch()) {
        return (int)$row['id'];
    }

    $db->prepare('INSERT INTO characters (player_id, name) VALUES (?, ?)')
       ->execute([$playerId, $name]);
    $charId = (int)$db->lastInsertId();

    $skillStmt = $db->prepare(
        'INSERT INTO character_skills (character_id, skill) VALUES (?, ?)'
    );
    foreach (CHARACTER_SKILLS as $skill) {
        $skillStmt->execute([$charId, $skill]);
    }

    // Starter gear in the backpack (item ids from the schema seed).
    foreach (STARTER_ITEMS as $itemId) {
        grantItem($charId, $itemId);
    }

    return $charId;
}

// Total bonus_hp from the character's equipped items (raises effective HP max).
function equippedHpBonus(int $charId): int
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(i.bonus_hp), 0)
         FROM character_items ci JOIN items i ON i.id = ci.item_id
         WHERE ci.character_id = ? AND ci.equipped_slot IS NOT NULL'
    );
    $stmt->execute([$charId]);
    return (int)$stmt->fetchColumn();
}

// Apply HP regenerated since last_regen_at, then stamp the marker.
function tickCharacterRegen(int $charId): void
{
    $db   = db();
    $stmt = $db->prepare('SELECT hp, hp_max, last_regen_at FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    if ($row['last_regen_at'] === null) {
        $db->prepare('UPDATE characters SET last_regen_at = NOW() WHERE id = ?')->execute([$charId]);
        return;
    }

    $elapsed = time() - strtotime($row['last_regen_at']);
    $regen   = (int)floor($elapsed * HP_REGEN_PER_MIN / 60);
    if ($regen <= 0) {
        return;
    }

    $effMax = (int)$row['hp_max'] + equippedHpBonus($charId);
    $newHp  = min($effMax, (int)$row['hp'] + $regen);
    $db->prepare('UPDATE characters SET hp = ?, last_regen_at = NOW() WHERE id = ?')
       ->execute([$newHp, $charId]);
}

// Build the full character view (vitals, base + effective stats/sub-stats,
// skills, equipment paperdoll, and backpack). Applies HP regen first.
function loadCharacter(int $charId): array
{
    tickCharacterRegen($charId);

    $db = db();

    $stmt = $db->prepare('SELECT * FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $c = $stmt->fetch();

    $stmt = $db->prepare('SELECT skill, value FROM character_skills WHERE character_id = ? ORDER BY skill');
    $stmt->execute([$charId]);
    $skills = [];
    foreach ($stmt->fetchAll() as $row) {
        $skills[$row['skill']] = (int)$row['value'];
    }

    // Split owned items into the equipped paperdoll and the backpack.
    $owned = ownedItems($charId);

    $equipment = array_fill_keys(EQUIPMENT_SLOTS, null);
    $inventory = [];
    foreach ($owned as $it) {
        if ($it['equipped_slot'] !== null) {
            $equipment[$it['equipped_slot']] = $it;
        } else {
            $inventory[] = $it;
        }
    }

    // Base values, plus effective = base + equipped bonuses.
    $base      = [];
    $effective = [];
    foreach (STAT_KEYS as $col => $key) {
        $base[$key]      = (int)$c[$col];
        $effective[$key] = $base[$key];
    }
    $subBase = [];
    $subEff  = [];
    foreach (SUBSTAT_KEYS as $key) {
        $subBase[$key] = (int)$c[$key];
        $subEff[$key]  = $subBase[$key];
    }
    $vitals = [
        'hp'          => (int)$c['hp'],
        'hp_max'      => (int)$c['hp_max'],
        'mana'        => (int)$c['mana'],
        'mana_max'    => (int)$c['mana_max'],
        'courage'     => (int)$c['courage'],
        'courage_max' => (int)$c['courage_max'],
    ];
    foreach ($equipment as $it) {
        if ($it === null) {
            continue;
        }
        foreach ($it['bonuses'] as $k => $v) {
            if (isset($effective[$k])) {
                $effective[$k] += $v;                // primary stat
            } elseif (isset($subEff[$k])) {
                $subEff[$k] += $v;                   // combat sub-stat
            } elseif (isset($vitals["{$k}_max"])) {
                $vitals["{$k}_max"] += $v;           // hp/mana/courage add to max
            }
        }
    }

    return [
        'id'                 => (int)$c['id'],
        'name'               => $c['name'],
        'vitals'             => $vitals,
        'stats'              => $base,
        'stats_effective'    => $effective,
        'substats'           => $subBase,
        'substats_effective' => $subEff,
        'skills'             => $skills,
        'equipment'          => $equipment,
        'inventory'          => $inventory,
    ];
}

function handleMyCharacter(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    json(200, loadCharacter($charId));
}
