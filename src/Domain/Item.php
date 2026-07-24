<?php
declare(strict_types=1);

namespace Gob\Domain;

// An item — either a definition or an owned instance (a joined
// character_items + items row). Pure; persistence lives in ItemRepository.
final class Item
{
    // Bonus columns surfaced in item detail (in payload order).
    public const BONUS_KEYS = [
        'str', 'dex', 'con', 'int', 'wis', 'cha', 'hp', 'mana', 'courage',
        'defense', 'protection', 'attack', 'penetration', 'perception',
    ];

    // Relative drop weight per rarity (rarer = lower).
    public const RARITY_WEIGHTS = [
        'common' => 100, 'uncommon' => 30, 'rare' => 8, 'epic' => 2,
    ];

    // The equipment slots an item of a given slot_type may occupy. Rings and
    // bracelets each have two numbered slots; everything else is 1:1.
    public static function slotsForType(string $type): array
    {
        return match ($type) {
            'ring'     => ['ring_1', 'ring_2'],
            'bracelet' => ['bracelet_1', 'bracelet_2'],
            default    => [$type],
        };
    }

    // Brief view of an item *definition* row (no owned-instance context).
    public static function brief(array $i): array
    {
        $bonuses = [];
        foreach (self::BONUS_KEYS as $k) {
            $v = (int)$i["bonus_$k"];
            if ($v !== 0) {
                $bonuses[$k] = $v;
            }
        }
        return [
            'item_id'   => (int)$i['id'],
            'name'      => $i['name'],
            'slot_type' => $i['slot_type'],
            'rarity'    => $i['rarity'],
            'kind'      => $i['kind'],
            'heal'      => (int)$i['heal_hp'],
            'bonuses'   => $bonuses,
        ];
    }

    // Pick one definition row from $items, weighted by rarity.
    public static function pickWeighted(array $items): array
    {
        $total = 0;
        foreach ($items as $it) {
            $total += self::RARITY_WEIGHTS[$it['rarity']] ?? 10;
        }
        $r   = random_int(1, $total);
        $acc = 0;
        foreach ($items as $it) {
            $acc += self::RARITY_WEIGHTS[$it['rarity']] ?? 10;
            if ($r <= $acc) {
                return $it;
            }
        }
        return $items[array_key_last($items)]; // unreachable; keeps the type happy
    }

    public function __construct(private array $row) {}

    // Detail view of an owned instance (the shape ownedItems() returned).
    public function toArray(): array
    {
        $r = $this->row;
        $bonuses = [];
        foreach (self::BONUS_KEYS as $k) {
            $v = (int)$r["bonus_$k"];
            if ($v !== 0) {
                $bonuses[$k] = $v;
            }
        }
        return [
            'char_item_id'  => (int)$r['ci_id'],
            'item_id'       => (int)$r['item_id'],
            'name'          => $r['name'],
            'slot_type'     => $r['slot_type'],
            'rarity'        => $r['rarity'],
            'weapon_skill'  => $r['weapon_skill'],
            'equipped_slot' => $r['equipped_slot'],
            'kind'          => $r['kind'],
            'heal'          => (int)$r['heal_hp'],
            'sell_value'    => (int)$r['sell_value'],
            'description'   => $r['description'] ?? '',
            'bonuses'       => $bonuses,
        ];
    }
}
