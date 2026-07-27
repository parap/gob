<?php
declare(strict_types=1);

namespace Gob\Domain;

// How a race feels about the player, on two independent axes (see
// future_implementation.md §2):
//
//   Hostility — how readily they attack on sight. Lowered by sparing.
//   Trust     — how far they open up. Raised only by actually helping them,
//               which nothing does yet; sparing deliberately does NOT move it.
//
// Both axes are tracked at nested scopes (person / site / province / generic)
// and blended, so direct experience outweighs rumour. Pure — persistence and
// the scope rows live in RelationshipRepository.
final class Relationship
{
    // Blend weights per scope, broadest last. 'npc' (the person scope) has no
    // table yet — it needs persistent NPC identity — but it keeps its weight
    // here so adding it later changes no arithmetic.
    public const WEIGHTS = ['generic' => 1, 'province' => 2, 'site' => 4, 'npc' => 8];

    // Scopes ordered broad → specific: the order inheritance walks.
    public const SCOPES = ['generic', 'province', 'site', 'npc'];

    // Both axes run 0-100.
    public const MIN = 0;
    public const MAX = 100;

    // Where a people stands before the player has done anything to them.
    // These are peoples, not body types or natures: a construct has no entry
    // here because "constructs" are not a people who can think about you.
    public const START_HOSTILITY = [
        'goblin'     => 80,
        'orc'        => 85,
        'ogre'       => 85,
        'giant'      => 80,
        'spiderfolk' => 75,
        'human'      => 70,   // the humans you meet in the wild are bandits
        'beastkin'   => 65,
        'wolf'       => 55,   // beasts fight from hunger, not hatred
        'tiger'      => 60,
        'naga'       => 55,
        'merfolk'    => 60,
        'triton'     => 60,
        'dwarf'      => 55,
        'olm'        => 45,   // deep-cave scholars, slow to anger
        'elf'        => 50,
        'fae'        => 50,
    ];
    public const START_HOSTILITY_DEFAULT = 75;
    public const START_TRUST = 0;

    // Nobody home to spare — keyed off what a thing IS, not who its people
    // are. A risen corpse and a war statue ignore the mercy stance (§3).
    public const UNSPARABLE_NATURES = ['undead', 'construct', 'plant'];

    // Race values that name no people, so no one is left to form an opinion.
    private const NO_PEOPLE = ['none', 'unknown', ''];

    // Share of encounters that won't be taken alive whatever your stance —
    // a flat roll for now, a `fanatic` monster tag later (§3).
    public const FANATIC_PCT = 25;

    // Deed magnitudes, applied at the most specific known scope and halved
    // outward from there (see RelationshipRepository::applyDeed).
    public const SPARE_HOSTILITY_DROP = 6;
    public const KILL_HOSTILITY_RISE  = 2;

    // Sparing teaches a race you are not an exterminator — nothing more. It
    // carries Hostility down to exactly Neutral and no further; the rest of
    // the ladder is bought with Trust, which only helping them raises (§2).
    // Derived from the ladder so the two can never drift apart.
    public const SPARE_HOSTILITY_FLOOR = self::HOSTILE_CURIOUS - 1;

    // The stage ladder. Hostility gates the bottom half, Trust the top.
    public const STAGE_LABELS = ['Monster', 'Curious', 'Neutral', 'Friendly', 'Ally'];
    public const HOSTILE_MONSTER = 70;    // at or above: attacks on sight
    public const HOSTILE_CURIOUS = 40;    // at or above: wary, but not rabid
    private const TRUST_FRIENDLY  = 25;
    private const TRUST_ALLY      = 60;

    public static function startingHostility(string $race): int
    {
        return self::START_HOSTILITY[$race] ?? self::START_HOSTILITY_DEFAULT;
    }

    public static function isSparable(string $nature): bool
    {
        return !in_array($nature, self::UNSPARABLE_NATURES, true);
    }

    // Whether anyone is there to remember what the player did. Statues, risen
    // corpses and walking vines have no kin to tell and no opinion to hold, so
    // no relationship rows are kept for them at all.
    public static function tracksOpinion(string $race): bool
    {
        return !in_array($race, self::NO_PEOPLE, true);
    }

    // Roll whether this particular enemy would rather die than be taken alive.
    public static function rollFanatic(): bool
    {
        return random_int(1, 100) <= self::FANATIC_PCT;
    }

    public static function clamp(int $v): int
    {
        return max(self::MIN, min(self::MAX, $v));
    }

    // Blend the scope values into the number an encounter actually uses.
    // $byScope holds raw per-scope values; a scope with no value inherits the
    // next-broader one, so a total stranger is simply their race's reputation.
    public static function blend(array $byScope): int
    {
        $inherited = 0;
        $sum       = 0;
        foreach (self::SCOPES as $scope) {
            $inherited = $byScope[$scope] ?? $inherited;
            $sum      += $inherited * self::WEIGHTS[$scope];
        }
        return (int)round($sum / array_sum(self::WEIGHTS));
    }

    public static function stage(int $hostility, int $trust): int
    {
        if ($hostility >= self::HOSTILE_MONSTER) return 0;
        if ($hostility >= self::HOSTILE_CURIOUS) return 1;
        if ($trust < self::TRUST_FRIENDLY)       return 2;
        if ($trust < self::TRUST_ALLY)           return 3;
        return 4;
    }

    public function __construct(
        private string $race,
        private int $hostility,
        private int $trust,
    ) {}

    public function hostility(): int { return $this->hostility; }
    public function trust(): int { return $this->trust; }
    public function stageIndex(): int { return self::stage($this->hostility, $this->trust); }

    public function toArray(): array
    {
        $stage = $this->stageIndex();
        return [
            'race'      => $this->race,
            'hostility' => $this->hostility,
            'trust'     => $this->trust,
            'stage'     => $stage,
            'stage_label' => self::STAGE_LABELS[$stage],
        ];
    }
}
