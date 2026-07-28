<?php
declare(strict_types=1);

use Gob\Domain\Character;
use Gob\Domain\Interrogation;
use Gob\Domain\Language;
use Gob\Domain\Relationship;

// Safety cap so a fight can never loop forever.
const MAX_COMBAT_ROUNDS = 60;

// Goblins drop an ear when slain — the tangible "proof" the elder's quest wants.
const GOBLIN_EAR_ITEM_ID = 19;

function monsterRepo(): \Gob\Repository\MonsterRepository
{
    return \Gob\Repositories::get(\Gob\Repository\MonsterRepository::class);
}

function relationRepo(): \Gob\Repository\RelationshipRepository
{
    return \Gob\Repositories::get(\Gob\Repository\RelationshipRepository::class);
}

function handleMonsters(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    $charId = ensureCharacter($pid, (string)$player['username']);
    $prov   = worldRepo()->currentProvinceId($charId);

    // Browsing the roster is not meeting anyone, so nothing is written to the
    // journal here — you only remember what you actually encountered.
    json(200, array_map(function ($m) use ($pid, $charId, $prov) {
        $row = $m->toArray();
        $row['facts'] = perceiveMonster($pid, $charId, $m->row(), $prov);
        return $row;
    }, monsterRepo()->all()));
}

// One blow: attack power blunted by the target's defense, then reduced by
// protection (which penetration erodes), with a small ±15% swing.
function combatDamage(int $power, int $defDefense, int $defProtection, int $atkPenetration): int
{
    $afterDefense = max(0, $power - $defDefense);
    $soak         = max(0, $defProtection - $atkPenetration);
    $base         = max(1, $afterDefense - $soak);
    return max(1, (int)round($base * random_int(85, 115) / 100));
}

function handleAttack(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $monsterId = (int)(body()['monster_id'] ?? 0);
    $m = monsterRepo()->find($monsterId);
    if (!$m) {
        json(404, ['error' => 'Monster not found.']);
    }

    $res              = resolveFight($player, $charId, $m);
    $res['character'] = loadCharacter($charId);
    json(200, $res);
}

// POST /api/character/mercy — flip the stance. Nothing announces what it costs
// or buys; the player finds that out by using it (§1: no directions).
function handleMercyStance(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    characterRepo()->setMercy($charId, (bool)(body()['on'] ?? false));
    json(200, ['character' => loadCharacter($charId)]);
}

// POST /api/combat/finish — kill the enemy still lying at your mercy and take
// the loot sparing forwent. Counts as an ordinary kill, no betrayal penalty (§3).
function handleFinish(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $window = characterRepo()->mercyWindow($charId);
    if (!$window || $window['expired']) {
        settleMercyWindow((int)$player['id'], $charId);
        json(400, ['error' => 'Nothing is at your mercy.']);
    }

    $m = monsterRepo()->find($window['monster_id']);
    characterRepo()->clearMercyWindow($charId);
    if (!$m) {
        json(404, ['error' => 'Monster not found.']);
    }

    $rewards = grantKillRewards($player, $charId, $m);
    applyKillDeed((int)$player['id'], $m, $window['province_id'], $window['site_id']);

    json(200, [
        'finished'  => $m['name'],
        'rewards'   => $rewards,
        'relation'  => relationView((int)$player['id'], $m, $window['province_id'], $window['site_id']),
        'character' => loadCharacter($charId),
    ]);
}

// POST /api/combat/leave — walk away now rather than waiting the window out.
// Identical outcome to letting it run down: you left them alive. It exists so
// sparing can be a decision the player makes, not just one they fail to undo.
function handleLeave(): void
{
    $player = requirePlayer();
    $charId = ensureCharacter((int)$player['id'], $player['username']);

    $window = characterRepo()->mercyWindow($charId);
    if (!$window) {
        json(400, ['error' => 'Nothing is at your mercy.']);
    }

    $m = monsterRepo()->find($window['monster_id']);
    settleMercyWindow((int)$player['id'], $charId, true);

    json(200, [
        'left'      => $m['name'] ?? 'It',
        'relation'  => $m ? relationView((int)$player['id'], $m, $window['province_id'], $window['site_id']) : null,
        'character' => loadCharacter($charId),
    ]);
}

// The attitude to report alongside a fight — null when the loser was a statue
// or a risen corpse and there is no people to hold one.
function relationView(int $playerId, array $m, ?int $provinceId, ?int $siteId): ?array
{
    $race = (string)($m['race'] ?? 'unknown');
    if (!Relationship::tracksOpinion($race)) {
        return null;
    }
    return relationRepo()->effective($playerId, $race, $provinceId, $siteId)->toArray();
}

// POST /api/combat/interrogate — question the enemy lying at your mercy.
// Needs some of their tongue (§4): this is the first thing a language buys and
// the first time sparing pays anything back. Costs the window either way — you
// squeeze them, then they crawl off, which still counts as sparing them.
function handleInterrogate(): void
{
    $player = requirePlayer();
    $pid    = (int)$player['id'];
    $charId = ensureCharacter($pid, (string)$player['username']);

    $window = characterRepo()->mercyWindow($charId);
    if (!$window || $window['expired']) {
        settleMercyWindow($pid, $charId);
        json(400, ['error' => 'Nothing is at your mercy.']);
    }

    $m = monsterRepo()->find($window['monster_id']);
    if (!$m) {
        characterRepo()->clearMercyWindow($charId);
        json(404, ['error' => 'Monster not found.']);
    }

    $race  = (string)($m['race'] ?? 'unknown');
    $skill = Language::skill($race);
    $level = (int)(loadCharacter($charId)['skills'][$skill] ?? 0);
    if ($level < Language::FRAGMENTS) {
        // Deliberately not an explanation: you hear noise and learn nothing,
        // rather than being told a skill exists that would fix it (§1).
        json(400, ['error' => 'They snarl something at you. It means nothing.']);
    }

    // Questioning ends the window: they talk, then they go.
    settleMercyWindow($pid, $charId, true);

    $said = Interrogation::heard(Interrogation::line($race), $level);

    // Intel: where their kin are. Revealing the site stamps found_at, so it
    // surfaces at the top of the discovered list like any fresh find.
    $intel = null;
    if (random_int(1, 100) <= Interrogation::intelChance($level)) {
        $site = worldRepo()->randomHiddenSite($pid, $window['province_id'], $race);
        if ($site) {
            worldRepo()->setSiteState((int)$site['id'], 'found');
            $intel = [
                'site' => $site['name'],
                'type' => $site['type'],
                // Flagged when it bears on something the player is already
                // chasing — the headline value interrogation is meant to have.
                'quest_relevant' => questRepo()->hasActiveKillQuest($pid, $race),
            ];
        }
    }

    $gold = 0;
    if (random_int(1, 100) <= Interrogation::stashChance($level)) {
        $gold = Interrogation::stashGold();
        addGold($player, $gold);
    }

    // It survived and it mattered: you spared it and it talked to you. That is
    // the bar for becoming a person rather than a spawn (§2), and a person can
    // eventually teach you what no village scholar knows.
    $promoted = null;
    if (Relationship::tracksOpinion($race)) {
        $npcId = npcRepo()->promote($pid, $m, $window['province_id'], $window['site_id']);
        if ($npcId !== null) {
            $npc      = npcRepo()->find($npcId, $pid);
            $promoted = ['id' => $npcId, 'name' => $npc['name']];
        }
    }

    json(200, [
        'monster'  => $m['name'],
        'promoted' => $promoted,
        'said'     => $said,
        'language' => [
            'skill'   => $skill,
            'value'   => $level,
            'fluency' => Language::fluency($level),
        ],
        'intel'     => $intel,
        'gold'      => $gold,
        'relation'  => relationView($pid, $m, $window['province_id'], $window['site_id']),
        'character' => loadCharacter($charId),
    ]);
}

// Close out a mercy window as a spare: the enemy was left alive, so the deed
// finally counts. That happens when the window runs out (it crawls off) and
// equally when the player walks away to another fight — $abandoned. Cheap
// no-op when there's nothing pending.
function settleMercyWindow(int $playerId, int $charId, bool $abandoned = false): void
{
    $window = characterRepo()->mercyWindow($charId);
    if (!$window || (!$window['expired'] && !$abandoned)) {
        return;
    }
    characterRepo()->clearMercyWindow($charId);

    $m = monsterRepo()->find($window['monster_id']);
    if (!$m) {
        return;
    }
    // Sparing lowers Hostility only, and only as far as wary coexistence —
    // liking has to be earned by helping, which no verb does yet (§2).
    relationRepo()->applyDeed(
        $playerId,
        (string)($m['race'] ?? 'unknown'),
        $window['province_id'],
        $window['site_id'],
        -Relationship::SPARE_HOSTILITY_DROP,
        0,
        Relationship::SPARE_HOSTILITY_FLOOR,
    );
}

// A body looted: gold into the settlement, the loot roll, and the goblin ear.
// Shared by an ordinary kill and by finishing a spared enemy.
function grantKillRewards(array $player, int $charId, array $m): array
{
    $db      = db();
    $rewards = ['gold' => 0, 'items' => []];

    $sid = $db->prepare('SELECT id FROM settlements WHERE player_id = ? ORDER BY id LIMIT 1');
    $sid->execute([$player['id']]);
    if ($settlementId = $sid->fetchColumn()) {
        $gold = (int)$m['reward_gold'];
        $db->prepare('UPDATE settlements SET gold = LEAST(capacity_gold, gold + ?) WHERE id = ?')
           ->execute([$gold, $settlementId]);
        $rewards['gold'] = $gold;
    }

    if ($m['loot_item_id'] !== null && (int)$m['loot_chance'] > 0
        && random_int(1, 100) <= (int)$m['loot_chance']) {
        grantItem($charId, (int)$m['loot_item_id']);
        $rewards['items'][] = itemRepo()->name((int)$m['loot_item_id']);
    }

    // Goblins yield an ear (proof). Guaranteed, on top of the normal loot roll.
    if (($m['race'] ?? '') === 'goblin') {
        grantItem($charId, GOBLIN_EAR_ITEM_ID);
        $rewards['items'][] = 'Goblin Ear';
    }

    advanceKillQuests((int)$player['id'], (string)($m['race'] ?? ''));
    return $rewards;
}

function applyKillDeed(int $playerId, array $m, ?int $provinceId, ?int $siteId): void
{
    relationRepo()->applyDeed(
        $playerId,
        (string)($m['race'] ?? 'unknown'),
        $provinceId,
        $siteId,
        Relationship::KILL_HOSTILITY_RISE,
    );
}

// Decide what the mercy stance does to a beaten enemy. Returns the outcome
// tag; 'spared' is the only one that withholds loot.
function mercyOutcome(bool $stance, array $m): string
{
    if (!$stance) {
        return 'off';
    }
    if (!Relationship::isSparable((string)($m['nature'] ?? 'mortal'))) {
        return 'unsparable';   // no one home to spare — bone and iron
    }
    if (Relationship::rollFanatic()) {
        return 'fanatic';      // this one would rather die
    }
    return 'spared';
}

// Simulate a fight between the hero and a monster row, persist the hero's HP,
// grant win rewards (gold + skill training + monster loot), apply the mercy
// stance to the beaten enemy, and return the result (outcome, rounds, log,
// hero_hp_after, rewards, mercy, relation, monster). Reused by both the arena
// (handleAttack) and exploration (handleSiteAdvance).
//
// $siteId is the site the fight happened at, if any: it decides how narrowly
// the deed is remembered (§2 — a cave's own community vs. the whole race).
function resolveFight(array $player, int $charId, array $m, ?int $siteId = null): array
{
    $db = db();

    // Starting another fight abandons anyone still lying at the player's
    // mercy — they were left alive, so bank that before the window is reused.
    settleMercyWindow((int)$player['id'], $charId, true);

    // Fresh hero numbers (regen already applied inside loadCharacter).
    $c = loadCharacter($charId);

    $stance     = characterRepo()->mercyStance($charId);
    $provinceId = worldRepo()->currentProvinceId($charId);

    // Which skill the equipped weapon trains (unarmed if bare-handed).
    $weapon          = $c['equipment']['weapon'] ?? null;
    $weaponSkillName = $weapon['weapon_skill'] ?? 'unarmed';
    $weaponSkillVal  = $c['skills'][$weaponSkillName] ?? 0;
    $attackSkill     = $c['skills']['attack'] ?? 1;

    // Attack power = weapon/gear attack + combat skills + a little muscle.
    $heroPower = $c['substats_effective']['attack']
        + $attackSkill + $weaponSkillVal
        + intdiv($c['stats_effective']['str'], 2);
    $heroPen  = $c['substats_effective']['penetration'];
    $heroDef  = $c['substats_effective']['defense'];
    $heroProt = $c['substats_effective']['protection'];

    $heroHp = $c['vitals']['hp'];
    $monHp  = (int)$m['hp'];

    $log   = [];
    $round = 1;
    while ($monHp > 0 && $heroHp > 0 && $round <= MAX_COMBAT_ROUNDS) {
        $d = combatDamage($heroPower, (int)$m['defense'], (int)$m['protection'], $heroPen);
        $monHp -= $d;
        $log[] = ['round' => $round, 'actor' => 'hero', 'damage' => $d, 'target_hp' => max(0, $monHp)];
        if ($monHp <= 0) {
            break;
        }

        $d2 = combatDamage((int)$m['attack'], $heroDef, $heroProt, (int)$m['penetration']);
        $heroHp -= $d2;
        $log[] = ['round' => $round, 'actor' => 'monster', 'damage' => $d2, 'target_hp' => max(0, $heroHp)];
        $round++;
    }

    $win = $monHp <= 0 && $heroHp > 0;

    // Persist the hero's HP; a defeated hero is left knocked out at 1 HP.
    $finalHp = $heroHp > 0 ? $heroHp : 1;
    $db->prepare('UPDATE characters SET hp = ? WHERE id = ?')->execute([$finalHp, $charId]);

    $rewards = ['gold' => 0, 'skills' => [], 'items' => []];
    $mercy   = ['stance' => $stance, 'outcome' => 'off', 'window' => null, 'forgone_gold' => 0];

    if ($win) {
        // Fighting trains the skills that did the work whatever happens to the
        // loser afterwards (capped at 100) — mercy costs loot, not practice.
        foreach (array_unique(['attack', $weaponSkillName]) as $sk) {
            $db->prepare('UPDATE character_skills SET value = LEAST(100, value + 1) WHERE character_id = ? AND skill = ?')
               ->execute([$charId, $sk]);
            $rewards['skills'][] = $sk;
        }

        $mercy['outcome'] = mercyOutcome($stance, $m);
        if ($mercy['outcome'] === 'spared') {
            // No looting a body that's still breathing. The deed itself waits
            // on the window: right now this one is only beaten, not spared.
            characterRepo()->openMercyWindow($charId, (int)$m['id'], $siteId, $provinceId);
            $mercy['window']       = ['seconds' => Character::MERCY_WINDOW_SECONDS];
            $mercy['forgone_gold'] = (int)$m['reward_gold'];
        } else {
            $loot             = grantKillRewards($player, $charId, $m);
            $rewards['gold']  = $loot['gold'];
            $rewards['items'] = $loot['items'];
            applyKillDeed((int)$player['id'], $m, $provinceId, $siteId);
        }
    }

    return [
        'outcome'       => $win ? 'win' : 'loss',
        'rounds'        => min($round, MAX_COMBAT_ROUNDS),
        'log'           => $log,
        'monster'       => ['id' => (int)$m['id'], 'name' => $m['name'], 'hp' => (int)$m['hp']],
        'hero_hp_after' => $finalHp,
        'rewards'       => $rewards,
        'mercy'         => $mercy,
        'relation'      => relationView((int)$player['id'], $m, $provinceId, $siteId),
        // You met it, so whatever you could perceive is now remembered.
        'facts'         => perceiveMonster((int)$player['id'], $charId, $m, $provinceId, $siteId, true),
    ];
}
