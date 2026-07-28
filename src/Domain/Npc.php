<?php
declare(strict_types=1);

namespace Gob\Domain;

// A notable NPC (unified table): village residents now, promoted monster
// individuals later. Pure; persistence lives in NpcRepository.
final class Npc
{
    // Resident NPCs seeded into a new player's home village. Only some
    // professions offer quests today; the rest are present but idle.
    //
    // `teaches` is the tongue an NPC will tutor if asked. The scholar has a
    // little Goblinish off travellers and prisoners — and says nothing about
    // it unprompted, because the understanding layer is never advertised (§1).
    public const VILLAGE_ROSTER = [
        ['profession' => 'elder',    'name' => 'Elder Maroun',   'race' => 'human', 'teaches' => null],
        ['profession' => 'merchant', 'name' => 'Merchant Dessa', 'race' => 'human', 'teaches' => null],
        ['profession' => 'healer',   'name' => 'Healer Orin',    'race' => 'human', 'teaches' => null],
        ['profession' => 'scholar',  'name' => 'Scholar Yves',   'race' => 'human', 'teaches' => 'goblin'],
    ];

    // Name parts for individuals promoted out of anonymous spawns. Deliberately
    // harsh-sounding rather than pretty: these are still goblins, and the point
    // is that one of them now has a name at all, not that they became elves.
    private const SYLLABLES_HEAD = ['Gr', 'Sk', 'Nur', 'Vex', 'Tor', 'Mug', 'Rak', 'Sn', 'Krel', 'Yig'];
    private const SYLLABLES_TAIL = ['ak', 'ish', 'ug', 'rit', 'na', 'oth', 'ka', 'esh', 'ur', 'ib'];

    // A promoted individual's calling. Not a profession in the village sense —
    // it is what they were doing when you decided not to kill them.
    public const SURVIVOR = 'survivor';

    public static function generateName(): string
    {
        return self::SYLLABLES_HEAD[array_rand(self::SYLLABLES_HEAD)]
             . self::SYLLABLES_TAIL[array_rand(self::SYLLABLES_TAIL)];
    }

    public function __construct(private array $row) {}

    public function race(): string { return (string)$this->row['race']; }
    public function name(): string { return (string)$this->row['name']; }

    // Promoted individuals came from a monster template; village residents did not.
    public function isPromoted(): bool
    {
        return !empty($this->row['monster_id']);
    }

    public function id(): int { return (int)$this->row['id']; }
    public function profession(): string { return (string)$this->row['profession']; }
    public function row(): array { return $this->row; }

    public function teaches(): ?string
    {
        return $this->row['teaches'] ?: null;
    }

    public function teachCeiling(): int
    {
        return (int)($this->row['teach_ceiling'] ?? 0);
    }

    // $offer is the quest offer view (or null) computed by the quest layer;
    // $tuition the training offer (or null) computed by the training layer.
    public function toArray(?array $offer = null, ?array $tuition = null): array
    {
        return [
            'id'         => (int)$this->row['id'],
            'name'       => $this->row['name'],
            'race'       => $this->row['race'],
            'profession' => $this->row['profession'],
            'offer'      => $offer,
            'tuition'    => $tuition,
        ];
    }
}
