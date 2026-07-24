<?php
declare(strict_types=1);

namespace Gob\Domain;

// A player's hero. Holds the raw persisted fields plus its trained skills and
// owned items, and computes the derived view (effective stats, perception,
// vitals) that the API exposes. Pure — no database access lives here.
final class Character
{
    // Layout / rules constants (formerly globals in character.php; nothing
    // outside the character code referenced them).
    public const HP_REGEN_PER_MIN = 60;

    // Skills every new character starts with (value 1). Plain strings, so a new
    // weapon needs no migration. 'attack' = strike with the equipped weapon.
    public const SKILLS = ['attack', 'unarmed', 'sword', 'axe', 'bow', 'flail'];

    // Combat sub-stats: a separate group from the six primary stats.
    public const SUBSTAT_KEYS = ['defense', 'protection', 'attack', 'penetration'];

    // Item ids a new character starts with (Rusty Sword, Leather Cap).
    public const STARTER_ITEMS = [1, 3];

    // The paperdoll slots. Two rings and two bracelets get numbered slots.
    public const EQUIPMENT_SLOTS = [
        'ring_1', 'ring_2',
        'bracelet_1', 'bracelet_2',
        'platemail', 'gauntlets', 'sleeves', 'pants', 'foot',
        'head', 'glasses',
        'weapon', 'shield', 'banner',
    ];

    // DB stat columns => the short keys the API/frontend use. Full column names
    // are used because INT is a reserved SQL keyword.
    public const STAT_KEYS = [
        'strength'     => 'str',
        'dexterity'    => 'dex',
        'constitution' => 'con',
        'intelligence' => 'int',
        'wisdom'       => 'wis',
        'charisma'     => 'cha',
    ];

    /**
     * @param array $row    a row from the `characters` table
     * @param array $skills skill name => value
     * @param array $owned  owned item rows (as returned by ownedItems())
     */
    public function __construct(
        private array $row,
        private array $skills,
        private array $owned,
    ) {}

    public function id(): int { return (int)$this->row['id']; }
    public function name(): string { return (string)$this->row['name']; }

    // The full API view — identical shape to the old loadCharacter() array:
    // vitals, base + effective stats/sub-stats, skills, equipment, backpack.
    public function toArray(): array
    {
        $c = $this->row;

        // Split owned items into the equipped paperdoll and the backpack.
        $equipment = array_fill_keys(self::EQUIPMENT_SLOTS, null);
        $inventory = [];
        foreach ($this->owned as $it) {
            if ($it['equipped_slot'] !== null) {
                $equipment[$it['equipped_slot']] = $it;
            } else {
                $inventory[] = $it;
            }
        }

        // Base values, plus effective = base + equipped bonuses.
        $base = [];
        $effective = [];
        foreach (self::STAT_KEYS as $col => $key) {
            $base[$key]      = (int)$c[$col];
            $effective[$key] = $base[$key];
        }
        $subBase = [];
        $subEff  = [];
        foreach (self::SUBSTAT_KEYS as $key) {
            $subBase[$key] = (int)$c[$key];
            $subEff[$key]  = $subBase[$key];
        }
        $vitals = [
            'hp'               => (int)$c['hp'],
            'hp_max'           => (int)$c['hp_max'],
            'hp_regen_per_min' => self::HP_REGEN_PER_MIN + (int)$c['regen_bonus'],
            'mana'             => (int)$c['mana'],
            'mana_max'         => (int)$c['mana_max'],
            'courage'          => (int)$c['courage'],
            'courage_max'      => (int)$c['courage_max'],
        ];

        $perceptionBonus = 0;
        foreach ($equipment as $it) {
            if ($it === null) {
                continue;
            }
            foreach ($it['bonuses'] as $k => $v) {
                if ($k === 'perception') {
                    $perceptionBonus += $v;              // derived sub-stat, applied below
                } elseif (isset($effective[$k])) {
                    $effective[$k] += $v;                // primary stat
                } elseif (isset($subEff[$k])) {
                    $subEff[$k] += $v;                   // combat sub-stat
                } elseif (isset($vitals["{$k}_max"])) {
                    $vitals["{$k}_max"] += $v;           // hp/mana/courage add to max
                }
            }
        }

        // Perception is derived from intelligence + dexterity, plus gear bonuses.
        $subBase['perception'] = $base['int'] + $base['dex'];
        $subEff['perception']  = $effective['int'] + $effective['dex'] + $perceptionBonus;

        return [
            'id'                 => (int)$c['id'],
            'name'               => $c['name'],
            'vitals'             => $vitals,
            'stats'              => $base,
            'stats_effective'    => $effective,
            'substats'           => $subBase,
            'substats_effective' => $subEff,
            'skills'             => $this->skills,
            'equipment'          => $equipment,
            'inventory'          => $inventory,
        ];
    }
}
