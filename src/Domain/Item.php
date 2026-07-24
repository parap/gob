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
