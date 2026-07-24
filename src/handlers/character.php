<?php
declare(strict_types=1);

use Gob\Repository\CharacterRepository;

// Character logic now lives in Gob\Domain\Character and
// Gob\Repository\CharacterRepository. These procedural wrappers keep the many
// existing call sites (combat, items, world, village, loot, explore, auth)
// working unchanged during the incremental migration to classes.

function characterRepo(): CharacterRepository
{
    static $repo = null;
    return $repo ??= new CharacterRepository(db());
}

// Create the character if the player has none yet. Returns character id.
function ensureCharacter(int $playerId, string $name): int
{
    return characterRepo()->ensure($playerId, $name);
}

// Apply passive HP regeneration up to now.
function tickCharacterRegen(int $charId): void
{
    characterRepo()->regen($charId);
}

// Total bonus_hp from equipped items.
function equippedHpBonus(int $charId): int
{
    return characterRepo()->equippedHpBonus($charId);
}

// The full character view (vitals, stats, skills, equipment, backpack).
function loadCharacter(int $charId): array
{
    return characterRepo()->load($charId)->toArray();
}

function handleMyCharacter(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    json(200, loadCharacter($charId));
}
