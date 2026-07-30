<?php
declare(strict_types=1);

use Gob\Domain\Fact;
use Gob\Domain\Perception;
use Gob\Repository\InfoRepository;

// The perception layer (future_implementation.md §7).
//
// One rule: walk a subject's facts, test each requirement against what the
// player currently knows and how the subject's people feel about them, show
// the ones that pass. Everything the design calls for downstream — the same
// monster reading differently on the fourth encounter, a cave turning out to
// be a settlement, a chief visibly lying — is authored as data against that
// rule rather than coded case by case.
//
// Two ways to look at a subject, and they are not the same thing:
//
//   recall*()   — what you remember, from meetings that actually happened.
//                 This is all a list can ever show you.
//   perceive*() — an encounter: what you already knew, plus the few new things
//                 this meeting was enough to take in. Writes to the journal.
//
// Browsing a roster is not meeting anyone, so it never produces new facts.

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

// The subject levels a monster is read at: the facts shared by its whole people,
// then the ones particular to this creature. Authored cheapest-first within each.
function monsterSubjects(array $m): array
{
    return [
        ['race', (string)($m['race'] ?? 'unknown')],
        ['monster', (string)$m['id']],
    ];
}

// Facts about a subject the player could perceive right now, flat and in
// authored order. Nothing is written and nothing is grouped — this is only the
// raw "what is available to notice" list.
function passingFacts(string $subjectType, string $subjectRef, array $ctx): array
{
    $passing = [];
    foreach (infoRepo()->factsFor($subjectType, $subjectRef) as $fact) {
        if ($fact->passes($ctx)) {
            $passing[] = $fact->toArray();
        }
    }
    return $passing;
}

// Everything the player remembers about these subjects, flat, in canonical
// channel order. The one thing a list is allowed to show.
function recalledFacts(int $playerId, array $subjects): array
{
    $flat = [];
    foreach ($subjects as [$type, $ref]) {
        foreach (infoRepo()->rememberedFor($playerId, $type, $ref) as $row) {
            $flat[] = ['channel' => (string)$row['channel'], 'content' => (string)$row['content']];
        }
    }
    $ordered = [];
    foreach (Fact::group($flat) as $group) {
        foreach ($group['facts'] as $text) {
            $ordered[] = ['channel' => $group['channel'], 'content' => $text];
        }
    }
    return $ordered;
}

// An encounter. You come away with what you already remembered plus the first
// few things you had not noticed before — how many depends on perception, so a
// sharp-eyed character reads a creature in two meetings and a dull one takes
// five. Facts are authored cheapest-first, so this paces the discovery rather
// than gating any part of it.
function perceiveSubjects(int $playerId, array $subjects, array $ctx): array
{
    $passing = [];
    foreach ($subjects as [$type, $ref]) {
        foreach (passingFacts($type, $ref, $ctx) as $fact) {
            $passing[] = $fact;
        }
    }

    $known = infoRepo()->knownIds($playerId);
    $limit = Perception::noticeLimit((int)($ctx['substat']['perception'] ?? 0));

    $fresh = [];
    foreach ($passing as $fact) {
        if (isset($known[(int)$fact['id']])) {
            continue;
        }
        $fresh[] = $fact;
        if (count($fresh) >= $limit) {
            break;
        }
    }
    infoRepo()->remember($playerId, array_column($fresh, 'id'));

    // Read back what is remembered now, so the encounter view is exactly the
    // journal plus whatever this meeting just added — never more.
    return Fact::group(recalledFacts($playerId, $subjects));
}

// What the player remembers of a monster, for a list they are merely browsing.
// One heading: this is memory, not a briefing.
function recallMonster(int $playerId, array $m): array
{
    $lines = array_column(recalledFacts($playerId, monsterSubjects($m)), 'content');
    if (!$lines) {
        return [];
    }
    return [[
        'channel' => Fact::RECALL_CHANNEL,
        'label'   => Fact::RECALL_LABEL,
        'facts'   => $lines,
    ]];
}

// What the player perceives of a monster they are actually facing.
function perceiveMonster(
    int $playerId,
    int $charId,
    array $m,
    ?int $provinceId = null,
    ?int $siteId = null,
): array {
    $race = (string)($m['race'] ?? 'unknown');
    $ctx  = perceptionContext($playerId, $charId, $race, $provinceId, $siteId);

    return perceiveSubjects($playerId, monsterSubjects($m), $ctx);
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
