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

    // How well the player speaks it, said three ways: a bare tag for lists, a
    // sentence about where they stand, and a clause for where a lesson would
    // leave them. One table so the thresholds can only be stated once.
    //
    // The tag is deliberately never enough on its own in prose — "you speak it
    // broken" is not something a person says — which is why the sentences are
    // authored rather than assembled. Reading is mentioned only from LITERACY
    // up, because that is where it starts being true (§5).
    private const BANDS = [
        //  below,          tag,            where you stand,                      where a lesson leaves you
        [1,                'not a word',   'You do not know a word of it.',       'you would still not know a word of it'],
        [10,               'a few words',  'You know a few words of it.',         'you would know a few words of it'],
        [self::LITERACY,   'broken',       'You speak it in broken fragments.',   'you would speak it in broken fragments'],
        [70,               'passable',     'You can get by in it, and read it.',  'you would get by in it, and read it'],
        [PHP_INT_MAX,      'fluent',       'You speak it fluently.',              'you would speak it fluently'],
    ];

    private static function band(int $value): array
    {
        foreach (self::BANDS as $band) {
            if ($value < $band[0]) {
                return $band;
            }
        }
        return self::BANDS[array_key_last(self::BANDS)];
    }

    // In words rather than numbers — the point is what they can perceive, not
    // the integer. For the character sheet and the study bar ("toward broken").
    public static function fluency(int $value): string
    {
        return self::band($value)[1];
    }

    // The same band as a sentence, for prose: "You speak it in broken fragments."
    public static function sense(int $value): string
    {
        return self::band($value)[2];
    }

    // And as a clause to hang off an offer: "Afterwards, you would get by in it."
    public static function senseAfter(int $value): string
    {
        return self::band($value)[3];
    }
}
