<?php
declare(strict_types=1);

namespace Gob\Domain;

// Whether the player can currently perceive a fact (future_implementation.md §7).
//
// A requirement is a small boolean tree over what the player knows and how a
// people feels about them, so the same secret can be reachable by different
// builds — understand the words *and* be trusted, or simply read it off their
// faces with enough Empathy:
//
//   { "any": [
//       { "all": [ {"skill":"lang_goblin","min":3}, {"trust":"goblin","min":20} ] },
//       { "skill":"empathy", "min":8 }
//   ] }
//
// Leaves:
//   {"skill":   "<name>",  "min": n}   a trained skill (languages included)
//   {"substat": "<name>",  "min": n}   a derived combat sub-stat, e.g. perception
//   {"trust":   "<race>",  "min": n}   the blended Trust of that people
//   {"hostility":"<race>", "max": n}   blended Hostility at or below n — how a
//                                      fact stays hidden while they still want
//                                      you dead, and surfaces once they don't
//
// Pure: the caller assembles the context, this only reads it.
final class Requirement
{
    // An empty or malformed requirement is public knowledge: anyone can see it.
    public static function passes(mixed $tree, array $context): bool
    {
        if (!is_array($tree) || $tree === []) {
            return true;
        }

        if (isset($tree['all'])) {
            foreach ((array)$tree['all'] as $child) {
                if (!self::passes($child, $context)) {
                    return false;
                }
            }
            return true;
        }

        if (isset($tree['any'])) {
            foreach ((array)$tree['any'] as $child) {
                if (self::passes($child, $context)) {
                    return true;
                }
            }
            return false;
        }

        return self::leaf($tree, $context);
    }

    private static function leaf(array $leaf, array $context): bool
    {
        foreach (['skill', 'substat', 'trust', 'hostility'] as $kind) {
            if (!isset($leaf[$kind])) {
                continue;
            }
            $have = (int)($context[$kind][$leaf[$kind]] ?? 0);
            if (isset($leaf['min']) && $have < (int)$leaf['min']) {
                return false;
            }
            if (isset($leaf['max']) && $have > (int)$leaf['max']) {
                return false;
            }
            return true;
        }
        return false;   // an unrecognised leaf reveals nothing
    }

    // Every skill/substat a tree tests, so a caller can explain what is missing
    // without evaluating the tree itself.
    public static function keys(mixed $tree, array &$out = []): array
    {
        if (is_array($tree)) {
            foreach (['all', 'any'] as $group) {
                foreach ((array)($tree[$group] ?? []) as $child) {
                    self::keys($child, $out);
                }
            }
            foreach (['skill', 'substat', 'trust', 'hostility'] as $kind) {
                if (isset($tree[$kind])) {
                    $out[] = $kind . ':' . $tree[$kind];
                }
            }
        }
        return $out;
    }
}
