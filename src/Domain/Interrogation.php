<?php
declare(strict_types=1);

namespace Gob\Domain;

// Squeezing a spared prisoner for what they know (future_implementation.md §4).
//
// This is the first thing a language is *for*, and the first time sparing pays
// anything at all: the body that would have dropped gold instead gives up where
// something is hidden, and says something that reframes what you thought you
// were fighting. Pure — the world lookups live in the handler.
final class Interrogation
{
    // What a prisoner of each race might say, in their own tongue. These are
    // written to be worth understanding: every one of them is a crack in the
    // "they are mindless raiders" story the village tells.
    public const LINES = [
        'goblin' => [
            'We took the grain because the winter took our fields.',
            'The warlord keeps the best of it. We eat what is left of what we steal.',
            'There are young ones in the deep chamber. They do not fight.',
            'Your elder knows us. He traded with my father, before.',
            'We buried what we could not carry where the road bends.',
            'The big cave is not a war camp. It is where we live.',
            'We counted your walls for seasons and never came. Ask yourself why we come now.',
        ],
    ];
    public const LINES_FALLBACK = ['They speak, but you catch nothing worth keeping.'];

    // Below this you hear noise, not language — the bar Interrogate is gated on.
    // At and above FULL_UNDERSTANDING every word lands.
    public const FULL_UNDERSTANDING = 50;

    // Chance the prisoner gives up something concrete (a place), and the chance
    // they admit to a buried stash. Both scale with how much you can follow.
    public const INTEL_BASE_PCT = 25;
    public const STASH_BASE_PCT = 15;
    public const STASH_GOLD     = [5, 30];

    public static function line(string $race): string
    {
        $pool = self::LINES[$race] ?? self::LINES_FALLBACK;
        return $pool[random_int(0, count($pool) - 1)];
    }

    // The fraction of what they say that you actually follow.
    public static function comprehension(int $language): float
    {
        return max(0.0, min(1.0, $language / self::FULL_UNDERSTANDING));
    }

    // Render a line as the player hears it. With a few words of the tongue most
    // of it is gaps and you catch a noun; with fluency you get the sentence.
    // This is the "GRAAAH becomes words" reveal, done as text rather than as a
    // special case — the same line simply resolves further as the skill grows.
    public static function heard(string $line, int $language): string
    {
        $p = self::comprehension($language);
        if ($p >= 1.0) {
            return $line;
        }

        $out = [];
        foreach (preg_split('/\s+/', $line) as $word) {
            // Longer words are the content words; bias them to survive so what
            // does land is meaningful ("...grain... ...winter...") rather than
            // a scatter of "the" and "we".
            $weight = mb_strlen(trim($word, '.,')) >= 5 ? 1.25 : 0.75;
            $out[] = (random_int(1, 100) / 100) <= $p * $weight ? $word : '…';
        }

        // Collapse runs of gaps so it reads as speech, not as a redaction.
        $text = preg_replace('/(?:… )+…/', '…', implode(' ', $out));
        return trim($text) === '…' || trim($text) === '' ? '…' : $text;
    }

    public static function intelChance(int $language): int
    {
        return (int)round(self::INTEL_BASE_PCT + $language * 0.75);
    }

    public static function stashChance(int $language): int
    {
        return (int)round(self::STASH_BASE_PCT + $language * 0.35);
    }

    public static function stashGold(): int
    {
        return random_int(self::STASH_GOLD[0], self::STASH_GOLD[1]);
    }
}
