<?php
declare(strict_types=1);

namespace Gob\Domain;

// How much of a subject one encounter leaves you with (future_implementation.md
// §7: "the same monster reading differently on the fourth encounter").
//
// Requirements decide *what* a player could perceive; this decides how much of
// it they take in at a time. Facts are authored cheapest-first, so a low
// perception does not lock anything away — it just means working down the list
// one meeting at a time instead of reading the creature at a glance.
final class Perception
{
    // One new thing is guaranteed: an encounter always teaches you something.
    public const NOTICE_BASE = 1;

    // Each of these many points of perception is one more thing noticed at once.
    public const NOTICE_PER = 6;

    // Even a sharp eye does not exhaust a subject in a single meeting.
    public const NOTICE_MAX = 4;

    public static function noticeLimit(int $perception): int
    {
        $extra = intdiv(max(0, $perception), self::NOTICE_PER);
        return min(self::NOTICE_MAX, self::NOTICE_BASE + $extra);
    }
}
