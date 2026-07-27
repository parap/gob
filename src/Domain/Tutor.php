<?php
declare(strict_types=1);

namespace Gob\Domain;

// Teaching rules (future_implementation.md §5). No hard level caps: every
// tutor instead has a semi-random **ceiling** for what they can teach, and a
// session closes a fraction of the gap between what you know and that ceiling.
// So early on almost anyone helps, and fluency eventually demands a better
// tutor than the village has — which for goblin means goblins.
final class Tutor
{
    // Ceiling range by who the tutor is, rolled once per tutor and kept.
    // A human village scholar has fragments off travellers and prisoners; a
    // native speaker of their own tongue skews high. Someone has to be met on
    // mild terms before they will teach at all — that gate is the relationship
    // model's job, not this class's.
    public const CEILING_SCHOLAR = [15, 35];
    public const CEILING_NATIVE  = [65, 95];

    // Fraction of the remaining gap a single session covers. The more you
    // already know, the less any one tutor can add.
    public const GAP_FRACTION = 0.35;

    // Tuition and time. Cost is per point gained, so the cheap early sessions
    // are the big ones and the last crumbs from a tutor cost the same each.
    public const GOLD_PER_POINT    = 12;
    public const SECONDS_PER_POINT = 20;

    public static function rollCeiling(bool $native): int
    {
        [$lo, $hi] = $native ? self::CEILING_NATIVE : self::CEILING_SCHOLAR;
        return random_int($lo, $hi);
    }

    // What one session with this tutor would add. Zero once the student has
    // caught up — "you already speak it better than I do".
    public static function gain(int $current, int $ceiling): int
    {
        $gap = $ceiling - $current;
        if ($gap <= 0) {
            return 0;
        }
        return max(1, (int)floor($gap * self::GAP_FRACTION));
    }

    public static function price(int $gain): int
    {
        return $gain * self::GOLD_PER_POINT;
    }

    public static function seconds(int $gain): int
    {
        return $gain * self::SECONDS_PER_POINT;
    }
}
