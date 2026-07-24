<?php
declare(strict_types=1);

namespace Gob\Domain;

// A notable NPC (unified table): village residents now, promoted monster
// individuals later. Pure; persistence lives in NpcRepository.
final class Npc
{
    // Resident NPCs seeded into a new player's home village. Only some
    // professions offer quests today; the rest are present but idle.
    public const VILLAGE_ROSTER = [
        ['profession' => 'elder',    'name' => 'Elder Maroun',   'race' => 'human'],
        ['profession' => 'merchant', 'name' => 'Merchant Dessa', 'race' => 'human'],
        ['profession' => 'healer',   'name' => 'Healer Orin',    'race' => 'human'],
        ['profession' => 'scholar',  'name' => 'Scholar Yves',   'race' => 'human'],
    ];

    public function __construct(private array $row) {}

    public function id(): int { return (int)$this->row['id']; }
    public function profession(): string { return (string)$this->row['profession']; }
    public function row(): array { return $this->row; }

    // $offer is the quest offer view (or null) computed by the quest layer.
    public function toArray(?array $offer = null): array
    {
        return [
            'id'         => (int)$this->row['id'],
            'name'       => $this->row['name'],
            'race'       => $this->row['race'],
            'profession' => $this->row['profession'],
            'offer'      => $offer,
        ];
    }
}
