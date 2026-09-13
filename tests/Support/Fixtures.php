<?php
declare(strict_types=1);

namespace Gob\Tests\Support;

// Row shapes the domain classes are built from, so a test states only the
// fields it cares about and the rest stay at plausible defaults.
final class Fixtures
{
    // A row from `characters`, as CharacterRepository::load() reads it.
    public static function characterRow(array $overrides = []): array
    {
        return $overrides + [
            'id'                => 1,
            'name'              => 'Test Hero',
            'mercy'             => 0,
            'strength'          => 1,
            'dexterity'         => 1,
            'constitution'      => 1,
            'intelligence'      => 1,
            'wisdom'            => 1,
            'charisma'          => 1,
            'defense'           => 0,
            'protection'        => 0,
            'attack'            => 0,
            'penetration'       => 0,
            'hp'                => 100,
            'hp_max'            => 100,
            'mana'              => 100,
            'mana_max'          => 100,
            'courage'           => 100,
            'courage_max'       => 100,
            'regen_bonus'       => 0,
            'training_skill'    => null,
            'training_ends_at'  => null,
            'training_gain'     => 0,
            'spared_monster_id' => null,
            'spared_at'         => null,
        ];
    }

    // An owned item as ItemRepository::owned() hands it over: already run
    // through Item::toArray(), so `bonuses` is a decoded map.
    public static function ownedItem(array $overrides = []): array
    {
        return $overrides + [
            'char_item_id'  => 1,
            'item_id'       => 1,
            'name'          => 'Rusty Sword',
            'slot_type'     => 'weapon',
            'rarity'        => 'common',
            'weapon_skill'  => 'sword',
            'equipped_slot' => 'weapon',
            'kind'          => 'gear',
            'heal'          => 0,
            'sell_value'    => 5,
            'description'   => 'A blade with more rust than edge.',
            'bonuses'       => ['attack' => 3],
        ];
    }

    // A row from `items` — the definition, not an owned instance.
    public static function itemRow(array $overrides = []): array
    {
        $zeroed = [];
        foreach (\Gob\Domain\Item::BONUS_KEYS as $k) {
            $zeroed["bonus_$k"] = 0;
        }
        return $overrides + [
            'id'           => 1,
            'name'         => 'Rusty Sword',
            'slot_type'    => 'weapon',
            'rarity'       => 'common',
            'weapon_skill' => 'sword',
            'kind'         => 'gear',
            'heal_hp'      => 0,
            'sell_value'   => 5,
            'description'  => 'A blade with more rust than edge.',
        ] + $zeroed;
    }

    // A row from `monsters`.
    public static function monsterRow(array $overrides = []): array
    {
        return $overrides + [
            'id'          => 1,
            'name'        => 'Goblin Scout',
            'level'       => 1,
            'hp'          => 30,
            'attack'      => 4,
            'defense'     => 1,
            'protection'  => 0,
            'penetration' => 0,
            'reward_gold' => 20,
            'race'        => 'goblin',
            'nature'      => 'mortal',
            'nation'      => null,
            'alignment'   => 'evil',
            'description' => 'A skittish goblin with a rusty dagger.',
        ];
    }

    // A row from `sites`.
    public static function siteRow(array $overrides = []): array
    {
        return $overrides + [
            'id'               => 1,
            'type'             => 'dungeon',
            'name'             => 'Goblin Cave',
            'state'            => 'found',
            'found_at'         => '2026-01-01 00:00:00',
            'progress'         => 0,
            'stages_json'      => '[1,2,5]',
            'road_terrain'     => null,
            'reward_gold'      => 50,
            'bonus_gold_rate'  => 0,
            'bonus_wood_rate'  => 0,
            'bonus_stone_rate' => 0,
            'bonus_regen'      => 0,
            'reward_item_id'   => null,
        ];
    }
}
