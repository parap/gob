<?php
declare(strict_types=1);

namespace Gob\Domain;

// Knowing a people's tongue. Stored as an ordinary character skill named
// `lang_<race>`, so it needs no schema of its own and shows up alongside the
// combat skills — but unlike those, it is never trained by use. Language comes
// from contact and tuition only (future_implementation.md §5).
//
// A character has no language skill row until someone teaches them, so absent
// means zero: not a word.
final class Language
{
    public const PREFIX = 'lang_';

    // Landmarks on the usual 0-100 skill scale.
    public const FRAGMENTS = 1;    // enough to interrogate a prisoner (§4)
    public const LITERACY  = 40;   // enough to read a book in it (§5)

    public static function skill(string $race): string
    {
        return self::PREFIX . $race;
    }

    public static function isLanguage(string $skill): bool
    {
        return str_starts_with($skill, self::PREFIX);
    }

    public static function raceOf(string $skill): string
    {
        return substr($skill, strlen(self::PREFIX));
    }

    // "lang_goblin" -> "Goblin tongue"
    public static function label(string $skill): string
    {
        return ucfirst(self::raceOf($skill)) . ' tongue';
    }

    // How well the player speaks it, in words rather than numbers — the point
    // is what they can perceive, not the integer.
    public static function fluency(int $value): string
    {
        if ($value <= 0)                return 'not a word';
        if ($value < 10)                return 'a few words';
        if ($value < self::LITERACY)    return 'broken';
        if ($value < 70)                return 'passable';
        return 'fluent';
    }
}
