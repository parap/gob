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

    public function __construct(private array $row) {}

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
