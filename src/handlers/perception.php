<?php
declare(strict_types=1);

use Gob\Domain\Fact;
use Gob\Repository\InfoRepository;

// The perception layer (future_implementation.md §7).
//
// One rule: walk a subject's facts, test each requirement against what the
// player currently knows and how the subject's people feel about them, show
// the ones that pass. Everything the design calls for downstream — the same
// monster reading differently on the fourth encounter, a cave turning out to
// be a settlement, a chief visibly lying — is authored as data against that
// rule rather than coded case by case.

function infoRepo(): InfoRepository
{
    return \Gob\Repositories::get(InfoRepository::class);
}

// What the requirement trees get to look at: trained skills, derived sub-stats,
// and the blended two-axis standing of the subject's people.
function perceptionContext(int $playerId, int $charId, ?string $race, ?int $provinceId, ?int $siteId): array
{
    $c = loadCharacter($charId);
    $ctx = [
        'skill'     => $c['skills'],
        'substat'   => $c['substats_effective'],
        'trust'     => [],
        'hostility' => [],
    ];
    if ($race !== null && \Gob\Domain\Relationship::tracksOpinion($race)) {
        $rel = relationRepo()->effective($playerId, $race, $provinceId, $siteId);
        $ctx['trust'][$race]     = $rel->trust();
        $ctx['hostility'][$race] = $rel->hostility();
    }
    return $ctx;
}

// Facts about a subject that the player can currently perceive, grouped by
// channel. $remember writes newly-seen ones into the journal — true when the
// player actually met the subject, false for a list they are merely browsing.
function perceive(
    int $playerId,
    int $charId,
    string $subjectType,
    string $subjectRef,
    ?string $race = null,
    ?int $provinceId = null,
    ?int $siteId = null,
    bool $remember = false,
    ?array $context = null,
): array {
    $ctx = $context ?? perceptionContext($playerId, $charId, $race, $provinceId, $siteId);

    $passing = [];
    foreach (infoRepo()->factsFor($subjectType, $subjectRef) as $fact) {
        if ($fact->passes($ctx)) {
            $passing[] = $fact->toArray();
        }
    }

    if ($remember && $passing) {
        infoRepo()->remember($playerId, array_column($passing, 'id'));
    }

    return Fact::group($passing);
}

// What the player perceives of a monster: its own instance facts plus the
// facts shared by its whole people.
function perceiveMonster(
    int $playerId,
    int $charId,
    array $m,
    ?int $provinceId = null,
    ?int $siteId = null,
    bool $remember = false,
    ?array $context = null,
): array {
    $race = (string)($m['race'] ?? 'unknown');
    $ctx  = $context ?? perceptionContext($playerId, $charId, $race, $provinceId, $siteId);

    $facts = [];
    foreach ([['race', $race], ['monster', (string)$m['id']]] as [$type, $ref]) {
        foreach (perceive($playerId, $charId, $type, $ref, $race, $provinceId, $siteId, $remember, $ctx) as $group) {
            $facts[] = $group;
        }
    }

    // Merge the two levels channel by channel, keeping the canonical order.
    $flat = [];
    foreach ($facts as $g) {
        foreach ($g['facts'] as $text) {
            $flat[] = ['channel' => $g['channel'], 'content' => $text];
        }
    }
    return Fact::group($flat);
}

// GET /api/knowledge — the journal: everything perceived so far, grouped by
// what it is about.
function handleKnowledge(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];

    $bySubject = [];
    foreach (infoRepo()->journal($pid) as $row) {
        $key = $row['subject_type'] . ':' . $row['subject_ref'];
        $bySubject[$key] ??= [
            'subject_type' => $row['subject_type'],
            'subject'      => $row['subject_ref'],
            'known'        => 0,
            'total'        => infoRepo()->countFor($row['subject_type'], $row['subject_ref']),
            'facts'        => [],
        ];
        $bySubject[$key]['known']++;
        $bySubject[$key]['facts'][] = [
            'channel' => $row['channel'],
            'label'   => Fact::CHANNELS[$row['channel']] ?? $row['channel'],
            'content' => $row['content'],
            'state'   => $row['state'],
        ];
    }

    json(200, ['subjects' => array_values($bySubject)]);
}
