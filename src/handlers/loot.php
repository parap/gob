<?php
declare(strict_types=1);

use Gob\Domain\Item;

// How long the hero must rest between loot searches.
const LOOT_COOLDOWN_SECONDS = 20;

// POST /api/loot/search — grant one rarity-weighted item, on a cooldown.
function handleLootSearch(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);
    $db     = db();

    // Enforce the cooldown.
    $stmt = $db->prepare('SELECT last_loot_at FROM characters WHERE id = ?');
    $stmt->execute([$charId]);
    $last = $stmt->fetchColumn();
    if ($last) {
        $elapsed = time() - strtotime($last);
        if ($elapsed < LOOT_COOLDOWN_SECONDS) {
            json(429, [
                'error'       => 'Your hero is still resting.',
                'retry_after' => LOOT_COOLDOWN_SECONDS - $elapsed,
            ]);
        }
    }

    $items = itemRepo()->allDefinitions();
    if (!$items) {
        json(500, ['error' => 'No items defined.']);
    }

    $picked = Item::pickWeighted($items);
    grantItem($charId, (int)$picked['id']);
    $db->prepare('UPDATE characters SET last_loot_at = NOW() WHERE id = ?')->execute([$charId]);

    json(200, [
        'found'            => Item::brief($picked),
        'cooldown_seconds' => LOOT_COOLDOWN_SECONDS,
    ]);
}
