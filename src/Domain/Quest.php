<?php
declare(strict_types=1);

namespace Gob\Domain;

// Quest templates (the catalogue) plus the pure view helpers. Instances live
// in the player_quests table; persistence + progress live in QuestRepository.
final class Quest
{
    // Keyed templates. A giver offers a template if its profession matches and
    // the player has no active/turned-in copy already.
    public const TEMPLATES = [
        'clear_goblins' => [
            'giver'         => 'elder',
            'title'         => 'Cull the Goblin Raiders',
            'objective'     => 'kill',
            'target_race'   => 'goblin',
            'target_count'  => 5,
            'reward_gold'   => 120,
            'reward_rep'    => 10,
            'proof_item_id' => 19,   // Goblin Ear — collected on each kill, consumed at turn-in
            'proof_count'   => 5,
            'blurb'         => 'Goblins have been raiding our supplies. Slay five of them and bring their ears as proof.',
            'dialog'        => "Ah — you've the look of someone who's handled a blade before. Good. We could use it.\n\n"
                . "For weeks now the goblins from the old caves have been creeping down after dark, carrying off our grain and tools. Vicious little things — the watch can't be everywhere at once.\n\n"
                . "Thin them out. Five should be enough to teach the rest to keep their distance. Bring back proof of the deed and there'll be coin in it for you — and the village won't forget the favour.",
        ],
    ];

    public static function template(string $key): ?array
    {
        return self::TEMPLATES[$key] ?? null;
    }

    // The public "offer" view an NPC shows (title + blurb + full dialogue).
    public static function offerView(string $key): array
    {
        $t = self::TEMPLATES[$key];
        return [
            'key'    => $key,
            'title'  => $t['title'],
            'blurb'  => $t['blurb'],
            'dialog' => $t['dialog'] ?? $t['blurb'],
        ];
    }

    // A quest instance's payload. $proof (or null) is computed by the repo,
    // which has the DB access to count owned proof items.
    public static function payload(array $q, ?array $proof): array
    {
        return [
            'id'           => (int)$q['id'],
            'title'        => $q['title'],
            'objective'    => $q['objective'],
            'target_race'  => $q['target_race'],
            'target_count' => (int)$q['target_count'],
            'progress'     => (int)$q['progress'],
            'reward_gold'  => (int)$q['reward_gold'],
            'reward_rep'   => (int)$q['reward_rep'],
            'state'        => $q['state'],
            'proof'        => $proof,
        ];
    }
}
