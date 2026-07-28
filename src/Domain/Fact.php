<?php
declare(strict_types=1);

namespace Gob\Domain;

// One thing the player can know about a subject (future_implementation.md §7).
//
// Everything knowable is a fact on a subject, gated by a Requirement over
// skills and relationship. The engine walks a subject's facts on every
// encounter and shows the ones that pass — which is the whole "same cave, more
// options over time" reveal, expressed as data rather than as code per case.
//
// Facts are authored at two levels: `race` facts are shared by every member of
// a people ("goblins keep their young in the deep chambers"), while `monster`
// / `site` / `npc` facts belong to one specific thing. Most spawns only ever
// carry race facts; instance facts arrive with persistent NPC identity.
final class Fact
{
    // Which key opens each channel. Skills therefore do double duty: they are
    // both power (combat reads them) and perception (this reads them).
    public const CHANNELS = [
        'observation'  => 'What you see',
        'emotion'      => 'What they feel',
        'speech'       => 'What they say',
        'lore'         => 'What you know',
        'tactical'     => 'What you reckon',
        'confidential' => 'What they only tell their own',
    ];

    public const SUBJECT_TYPES = ['race', 'monster', 'site', 'npc'];

    public function __construct(private array $row) {}

    public function id(): int { return (int)$this->row['id']; }
    public function channel(): string { return (string)$this->row['channel']; }

    public function requirement(): array
    {
        $raw = $this->row['requirement_json'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        return $raw ? (json_decode((string)$raw, true) ?: []) : [];
    }

    public function passes(array $context): bool
    {
        return Requirement::passes($this->requirement(), $context);
    }

    public function toArray(): array
    {
        return [
            'id'      => $this->id(),
            'channel' => $this->channel(),
            'label'   => self::CHANNELS[$this->channel()] ?? $this->channel(),
            'content' => (string)$this->row['content'],
        ];
    }

    // Passing facts grouped by channel, in the canonical channel order so the
    // encounter view reads the same way every time.
    public static function group(array $facts): array
    {
        $out = [];
        foreach (array_keys(self::CHANNELS) as $channel) {
            $in = array_values(array_filter($facts, fn(array $f) => $f['channel'] === $channel));
            if ($in) {
                $out[] = [
                    'channel' => $channel,
                    'label'   => self::CHANNELS[$channel],
                    'facts'   => array_map(fn(array $f) => $f['content'], $in),
                ];
            }
        }
        return $out;
    }
}
