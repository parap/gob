# Goblin — Future Implementation Plan

Forward-looking design for the **"change your model of the world"** RPG layer
(talk to / spare / befriend the creatures you used to only fight). Companion to
`ideas.txt` (the raw brainstorm). This file is the **agreed direction** distilled
from a design discussion on 2026-07-24 — a plan to build against later, **not yet
implemented**.

Grounded in the current engine: per-player province world, sites delved from the
Exploration tab, auto-resolved turn-by-turn combat (`resolveFight()` in
`src/handlers/combat.php`), monsters carrying `race`/`alignment`/tags,
VARCHAR-keyed extensible `character_skills`.

---

## 1. Core principle

Progression changes **what the player can perceive and do**, not raw power.
First hours = ordinary hack-and-slash. Over time the *same* monsters and places
reveal deeper layers. See `ideas.txt` for the full vision; this doc is the
practical, agreed subset.

### Two layers: hidden & opt-in  (AGREED)

The game is **two layers**, and the deep one is **hidden and opt-in**:

- **Surface layer** — an ordinary, *complete* hack-and-slash: seek out a quest, kill
  monsters, take gold/loot. A player who does only this has a whole finished game and
  is never told they're missing anything.
- **Understanding layer** — the "change your model of the world" content: what monsters
  actually say/mean, alliances, unique quests, story. Reachable only by **investing**
  in understanding (language, Empathy, Lore). Without the investment the player
  literally cannot perceive it — the info model (§7) renders goblin speech as `"GRAAAH"`
  until `Language` is high enough. The architecture already enforces this.

**No directions.** We do NOT signpost the deep layer — no NPC pitch, no quest marker,
no tutorial nudge. Discovery is **intrinsic + environmental**:
- *Intrinsic*: the player tires of pure grinding and experiments with options that are
  quietly available (the tutor exists; the mercy toggle exists) — nothing tells them to.
- *Environmental cues*: gibberish that is visibly **consistent, not random** (clearly a
  language); **untranslatable goblin writings, books, and a few (sparse) notes** found
  as loot (you can't read them yet — the itch is the hook); observations that don't fit
  ("mindless monsters" with children's toys and sick elders).

**Two currencies.** Surface pays in **gold/gear**; the understanding layer pays in a
**different kind** — information, allies, unique quests, story, safe passage. So neither
playstyle invalidates the other: the murder-hobo isn't "playing wrong," and the curious
player isn't just min-maxing loot.

**Accepted tradeoff:** with no directions, *fewer* players find the deep layer — fine,
because the surface game stands alone. Guardrail: **"no directions" ≠ "no cues."** The
cues must exist in the world (consistent speech, readable-*looking* books, repeating odd
observations) so the thread is there to be pulled by anyone who looks.

**Two is a starting lens, not a wall.** The long-term goal is a **spectrum of
playstyles**, not a binary of exterminator vs. diplomat — e.g. trader, tactician,
lorekeeper, manipulator (uses info to deceive/exploit, not just befriend), peacemaker,
and everything between. These should **emerge** from the orthogonal systems (combat,
perception/info, relationships, economy, knowledge) rather than be hard-coded. So build
the two poles first (gold/gear vs. understanding), but **don't design the binary in as a
hard wall** — keep the systems independent so more styles fall out later.

**For the current stage, the player is the initiator of first contact.** The world
unfolds mainly because the player's own *verbs multiply* on the same encounter.

Monster-initiated interaction is **NOT rejected in principle** — monsters offering
quests, seeking help, or otherwise reaching out is fine and welcome later. The one
thing specifically **parked for now** is monsters approaching to *teach the player
their language* (an "envoy shows up and gifts you words"). That felt unbelievable:
language should be earned/learned by the player (§5), not handed over by a race that
was just looted. Other forms of monster-initiated contact can come once trust is
actually established.

---

## 2. Relationship model — TWO axes × FOUR scopes  (KEY DECISION)

### The two axes

A race/community/individual does not have a single "standing" number. It feels (at
least) two separate things about the player, moved by different inputs:

- **Hostility / Fear** — how much they attack on sight.
- **Trust / Liking** — how much they help / open up to the player.

Rules:
- **Sparing lowers Hostility only.** Robbing a goblin and leaving it alive does
  NOT make goblins like you — it teaches them you're not a pure exterminator.
  → Sparing can only move Hostility from *"kill on sight"* to *"wary coexistence"*.
  **Sparing CAPS at Neutral.**
- **Trust/Liking rises only from actually HELPING** — giving food/medicine,
  completing tasks for them, killing what raids *them*. This is the only path
  Neutral → Friendly → Ally.
- Therefore: **you cannot befriend a race by looting-and-sparing. You can only
  stop being its enemy.** Friendship is earned deliberately, later.

Stage ladder (from ideas.txt §4): `0 Monster → 1 Curious → 2 Neutral →
3 Friendly → 4 Ally`. Sparing gets you to ~Neutral; help carries the rest.

(The full CK-style axis set — Respect / Gratitude / Hatred / Debt / Curiosity —
is a possible later enrichment; start with Hostility + Trust.)

### The four scopes  (who holds the opinion)

Each axis is tracked at **four nested scopes**, most-specific to broadest, each with
a blend weight:

| Scope | Who | Weight |
|---|---|---|
| **Person** | a specific *known* individual (a goblin you've spared/interrogated/named) | ×8 |
| **Site** | the community of one site/cave | ×4 |
| **Province** | the tribe across a province | ×2 |
| **Generic** | the whole race, worldwide — reputation / rumor | ×1 |

**Effective attitude an encounter uses** (computed per axis):
```
effective = (8·person + 4·site + 2·province + 1·generic) / 15
```
Normalizing by 15 keeps it on the same 0-100 scale, so all mercy/verb thresholds
work unchanged. (Weights are tunable; the point is person ≫ site > province >
generic — direct experience outweighs rumor.)

**Inheritance.** A scope with no record yet **inherits its parent's value** (generic
is the root). So for a total stranger, person = site = province = generic and
`effective = generic`. As you build specific relations, the inner scopes diverge and
dominate. This is also how a **new area's first impression** works: unmet goblins in
a new province start at your *generic* (they've heard of you), then your deeds with
them push the inner scopes.

**Propagation of a deed** (magnitude M) — full at the most-specific *known* scope,
then **halving outward** ("more slightly outside"), so word spreads but weakly:
- Known individual: person `+M`, site `+M/2`, province `+M/4`, generic `+M/8`.
- Anonymous mook (site is the most specific scope): site `+M`, province `+M/2`,
  generic `+M/4`.

So local deeds mostly shape local opinion; generic is effectively the diminishing-
weight sum of everything you've done, and it seeds every new first impression. This
closes the loop: **person → (bleeds up to) site → province → generic → (seeds) the
next person/site.**

**Cost note:** the *person* scope needs persistent NPC identity (§9) — most mooks
never get a person record. A goblin is "promoted" to a known individual only when it
survives and matters (spared + interrogated/named). Build order: generic → province
→ site → person (**person last — it's the most expensive**).

---

## 3. Mercy mechanic  (AGREED)

- **Persistent `mercy` stance on the character: ON / OFF.** Applied **post-fight**
  (we keep the cheap model — no interactive mid-fight combat rework).
- Combat still auto-resolves to a win. THEN mercy decides the beaten enemy's fate.
- **Mercy costs loot.** Sparing = you don't loot the body → forgo the fight's
  **gold reward and loot-item roll**. You KEEP combat-skill training (you fought).
  Clean, legible trade: **loot vs. de-escalation**. A greedy player runs mercy OFF,
  farms loot, and stays blind forever — intentional.
- **Unsparable races ignore the toggle.** Undead / constructs (and likely demons):
  no one to spare → they die, full loot, mercy has no effect. Costs the player
  nothing, and teaches that *some* things really are just monsters (makes the
  sparable ones land harder). Keyed off `race`/`alignment`.
- **Some enemies fight regardless of mercy.** A share of each encounter are
  fanatics/berserkers who won't be taken alive → mercy ignored, they die, full loot.
  **Decision: random per-encounter roll for now** (e.g. ~25% won't yield); move to
  a `fanatic` subtype/tag later.

### The mercy window (~30s)  (AGREED)

When mercy spares a beaten sparable enemy, it is **at the player's mercy for ~30
seconds** (fits the existing live-ticking UI; diegetically it's stunned/wounded and
crawls off if ignored). Actions during the window:

- **Finish** — kill it anyway and take the loot you'd forgone.
  **Decision: treats as a NORMAL kill** (no extra betrayal penalty).
- **Interrogate** — *only if the player knows some of the race's language.* See §4.
- **(do nothing)** — window expires, enemy flees, outcome settles as a plain spare.

### Reciprocal mercy — monsters can spare the PLAYER  (AGREED)

Mercy runs both ways. When the player **loses** a fight, the outcome depends on the
race's attitude toward the player (its Hostility/Trust — §2). There is no permadeath
(the engine already knocks a defeated hero to 1 HP); this makes the *loss outcome*
vary by reputation instead of being flat:

- **Hostile / high-Hostility race** → shows no mercy: the downed player is finished
  and **robbed** — harsher penalty (lose some gold, possibly a backpack item, sent
  back to the home settlement, longer recovery).
- **Neutral** → **spared**: knocked out, wakes at low HP, minor/no loss. "You're not
  worth killing."
- **Friendly / Ally** → **spared and possibly helped**: returned safely, little or no
  loss, maybe even patched up (some HP restored). Your allies don't loot your body.
- **Random "no-mercy" enemies** (mirror of the fanatics in §3) → may kill/rob the
  downed player regardless of attitude.

**Thematic payoff:** because *sparing* is what lowers a race's Hostility, the player
who spares goblins will find goblins sparing them back. Mercy is reciprocal — your
reputation literally decides whether you wake up robbed or unharmed. (Exact
penalty/heal tuning is open.)

---

## 4. Interrogation  (AGREED)

- **Requires language** of the interrogated race (see §5). This is the real, early,
  non-saintly reason to learn a language: squeeze prisoners for intel.
- **Yields a MIX**: intel (hidden site locations), Knowledge/lore, sometimes gold/loot.
- **Ties to the player's ACTIVE QUESTS**: interrogation has a **chance to return
  information relevant to a quest the player currently has** (e.g. "where is the
  stolen caravan", "which cave the child was taken to"). Quest-relevant info is the
  headline value; generic intel/lore is the fallback. Chance-based, scaled by
  language level (and maybe Empathy — reading whether they're lying).

---

## 5. Language & skill training

Language is trained by **contact / tuition**, NEVER by fighting (fighting trains
combat skills only). This section generalizes: **tutors can train any social skill**;
combat skills still self-train through use.

### Hubs (villages / towns)

- **The player's home `settlement` IS their village hub** — a place with resident
  NPCs and services (tutors now; merchants, quest-givers later). No separate hub
  concept; we add NPCs/services onto the existing settlement.
- **A province MAY (or may not) contain a village/town** too — a non-combat hub
  with its own services. Some provinces have one, some don't (generated). Likely a
  `town` site type and/or `city`-terrain provinces.

### Tutors — semi-random quality, diminishing returns  (AGREED)

No hard level caps. Each tutor has a **teaching ceiling** for a given tongue/skill —
a **semi-random** value biased by who they are:

- Native **goblin** teaching goblin-tongue → skews **high** (but only reachable **if
  approached mildly**: Hostility low → Neutral+; a specific known goblin = person
  scope, §2/§9).
- Human **village scholar** → skews **low** (traveled scholar / captured informant).
- Individual spread either way (a rare well-travelled human may know a lot; a feral
  goblin little).

**Diminishing returns:** a training session adds an amount based on the **gap between
your current level and the tutor's ceiling** — the more you already know, the less a
given tutor can add. At ~their ceiling they can teach no more ("you already speak
better than I do") and you must find a better tutor. So early on almost any tutor
helps; **fluency requires high-ceiling tutors — which for goblin means goblins.**
(This replaces the earlier hard-cap idea.)

The loop payoff stands: spare → Hostility drops → a goblin will teach you → language
→ interrogate/talk deeper → befriend.

### Tuition — variable cost, always + time  (AGREED)

- **Cost is variable per tutor/level:** some want **gold**, some want a **quest
  done**, possibly both. Kept flexible for now.
- The player can **request a quest as the tuition** — ask the tutor for a task
  instead of (or as well as) paying gold.
- **Training always takes TIME** — a timed job (fits the rates+timestamps model:
  computed on read, no background worker). Pay/accept the quest up front; the level
  lands when the job completes.
- **One training slot** (starting point): only one skill trains at a time; the slot
  is occupied until the job finishes, then the skill goes up and the slot frees. Time
  is the real gate. (A multi-slot queue is an easy later upgrade.)

### Books — unlocked once language passes a threshold  (AGREED)

Once your skill in a tongue exceeds ~a mid ("readable literacy") threshold, you can
**read books** written in it. Each book gives:

- a **small language bump** (reading practice; also diminishing — a book has its own
  low ceiling).
- **Knowledge** in history / culture / folklore (feeds the info model §7 and §8).
- a **chance to help an active quest** — same "relevant clue" mechanic as Interrogate
  (§4): a book may contain something a current quest needs.

Sources: loot, bought in a village/town hub, or found in dungeon libraries/archives
(ties to the "Archives" that resolve contradictory histories — ideas.txt §10 /
"contradictory accounts").

### Other sources (keep)

- Rare **phrasebook / captured writings** loot → jump-start the first words.
- A **neutral intermediary** (ideas.txt §2 ladder: animals → wolves → …).

**First concrete payoff of language = Interrogate (§4)**, long before "be friends".

---

## 6. How the world unfolds — verbs multiply on the SAME encounter

For now, first contact is player-driven: the escalation is gated by
Hostility/Trust stage + language + Knowledge. (Monsters may initiate quests/contact
later, once trust exists — see §1; they just don't come to gift the player language.)

| Player state | Verbs available on a goblin encounter |
|---|---|
| Stage 0, no language | Fight. (Enemy reads as a loot piñata.) |
| Sparing underway | Fight → win → Spare (mercy). Mercy window: Finish / (do nothing). |
| Some language known | + **Interrogate** in the mercy window. |
| Neutral + language | + **Talk** — enemies stop attacking on sight; simple exchange. |
| Neutral + helped (Trust up) | + **Trade / accept Quests**; the site shows its true layers (kitchen, children, shaman). |
| Ally | + **unique quests, alliance** (they can call on / fight alongside you). |

Same cave, more options over time = the "I used to be blind" payoff.

---

## 7. Information model — the perception framework  (AGREED)

The framework the world's knowledge runs on, and the substrate everything else
(NPCs, places, combat reads, quests) plugs into. **Skills are not replaced by it** —
they keep their mechanical jobs (combat resolves off skills; Survival heals; tutoring
raises numbers). The info model is a **read-layer** that uses skills + relationship as
*keys* to decide what the player perceives. It's what actually powers §6 (verbs
multiply), place layers, skill-as-sense checks, tactical reads, and interrogation/book
clues.

### The atom — an "info fact"

Everything the player can *know* is a **fact** attached to a subject, with:
- **subject** — an NPC type, a specific NPC, a site, a monster, or an event.
- **content** — the text/data revealed (`"has pups"`, `"this is a settlement, not a
  lair"`, `"the chief is lying"`).
- **category / channel** — which key reveals it (see table).
- **reveal requirement** — a **boolean expression** over `skill ≥ n` / `trust ≥ n`
  conditions (AND / OR / nested — see grammar below).

On every encounter the engine walks the subject's facts, tests each requirement
against the player's current skills + relationship, and shows the ones that pass. That
one rule reproduces "same wolf, four encounters", "the cave is really a village", and
"the chief is lying" — all as data, no bespoke code per case.

### Granularity — BOTH type and instance  (AGREED)

- **Type-level** facts are shared by all of a race ("goblins keep their young in the
  deep chambers"). Cheap; deliver the general "I was blind about goblins" reveal.
- **Instance-level** facts belong to a specific *known* NPC (its name, a personal
  secret, this shaman's grudge) and ride the **person scope** (§2). Most mooks carry
  only type facts; an NPC gains instance facts once "promoted" to a person record.

### Channels — skills are the keys

Each channel is driven by a skill or relationship value, so skills do double duty
(power *and* perception key):

| Channel | Key | Example fact |
|---|---|---|
| Observation | Perception | "tracks of small feet lead deeper" |
| Emotion / intent | Empathy | "(scared)", "(he's lying)" |
| Speech | Language(race) | `"Please don't kill us."` |
| Nature / lore | Lore / Biology | "this one is a shaman, not a warrior" |
| Domain checks | Botany / Medicine / Politics | "(these berries are poisonous)" |
| Tactical | Tactics | "archer + commander; win chance 73%" |
| Confidential | Trust(race) — the **4-scope blend** (§2) | "we never told humans this story" |

Combat skills stay **purely mechanical** (they drive `resolveFight`); the info model
only *reads* the others.

### Reveal-requirement grammar — full boolean  (AGREED)

A requirement is a **boolean expression** over conditions — **AND, OR, and any
nesting**, no AND-only limitation. Represented as a small recursive tree:
- **leaf**: `{skill: "language_goblin", min: 3}` or `{trust: "goblin", min: 20}`
- `{all: [ … ]}` = AND (every child must hold)
- `{any: [ … ]}` = OR (at least one child)
- freely nested.

Example — a goblin secret reachable *either* by understanding the words + trust,
*or* by pure high Empathy (read off their faces):
```
{ any: [
    { all: [ {skill:"language_goblin", min:3}, {trust:"goblin", min:20} ] },
    { skill:"empathy", min:8 }
] }
```
The evaluator walks the tree against the player's skills + blended Trust (§2), so the
same fact can be reached by different builds/playstyles from the start.

### Player knowledge journal + memory states — Remembered + Shared now  (AGREED)

A revealed fact is logged to the player's **knowledge journal** with a state:
- **Remembered** — you perceived it (baseline; in the journal).
- **Shared** — you **told an NPC** this fact. An *action with consequences*: shifts
  relationships, opens quests, changes the world (tell a goblin "your chief is lying";
  tell a lord "the goblins have your artifact").
- **Verified** and **Common knowledge** are **deferred** — they arrive with the
  contradictory-truth layer below.

### Contradictory truth — LATER layer  (AGREED, deferred)

Some subjects carry *competing* claims from different sources ("humans murdered us" vs
"humans defended themselves"). You hold both as unverified **claims**; the real truth
is another fact gated behind higher requirements (`History + Archives/books +
Witnesses`) that **Verifies** one. Until then you act on uncertain info. Deferred — it
needs the Verified state and books/archives (§5) in place first.

### How it attaches to the rest

- **NPC/monster encounter view** = the facts that currently pass, grouped by channel.
  This *is* §6's verb/info escalation, now data-driven.
- **Sites/places** carry observation/lore facts → the "layers of reality" (a lair that
  is really a settlement).
- **Pre-fight** Tactics facts (composition, win %).
- **Quests consume facts:** interrogation clues and book clues are just **facts tagged
  to a quest**; revealing/(later)Verifying them advances it. This is *why* the info
  model is designed before quests — quests will read from it.

**One-line framework:** *everything knowable = a fact on a subject, gated by an
AND-requirement over skills + relationship; once perceived it's Remembered in the
journal and can be Shared. Skills are both power (mechanics) and keys (perception).*

---

## 8. Village, NPCs, quest-givers & quests  (AGREED)

### NPCs — one unified concept  (AGREED)

**One `npcs` table holds ALL notable NPCs** — village residents (chief, merchant,
scholar-tutor, hunter) *and* promoted monster individuals (a spared + named goblin).
Each NPC has: `race`, `profession`, a **location** (settlement / site / province),
`name`, plus its own info-facts (§7), its own relationship (§2 person scope), and the
quests it offers. Anonymous combat spawns are NOT NPCs — a spawn becomes one only when
"promoted" (survives + matters). A **village is then just a location populated with
NPCs.**

### The village / hubs  (AGREED)

- **Home settlement = the player's village**, populated mainly by **your own-race
  (human) NPCs**.
- **No hard lock:** other-race quest-givers can also be present initially (a lone /
  neutral / already-friendly other-race NPC). Own-race skews the home village, but the
  world does NOT forbid early other-race givers.
- Provinces MAY also contain town/village hubs (own- or other-race).

### Thematic spine

Home village = where you first see the world **wrong**: the elder gives the classic
opening — *"goblins are raiding our supplies, clear the cave."* As you spare/befriend
goblins, **goblin quest-givers become available and reframe the same conflict from the
other side.** The arc is literally lived through who's willing to give you quests.

### Quest-givers — profession + needs, tier-gated by relationship  (AGREED)

Each NPC generates quests from its **profession + needs** (the ×100-content model):
Hunter → meat/arrows, Shaman → herbs/rare skull, Chief → a dispute, Merchant → a lost
caravan, etc. Needs pool: Food / Medicine / Protection / Knowledge / Revenge / Mate /
Religion. **The tier a giver will offer is gated by relationship stage:**

| Stage | Offers |
|---|---|
| Curious | T1 (fetch / kill / find) |
| Neutral | T1–T2 (save a child, heal the sick) |
| Friendly | T2–T3 (catch a thief, solve a murder) |
| Ally | T3–T4 (settle a clan dispute, forge an alliance) |

So better quests are **earned** — no goblin "settle our clan war" until goblins trust you.

### Quests — template → instance  (AGREED)

- **Procedural backbone**: templates in code `{tier, need, objective_type, text,
  reward_spec}`; hand-authored "special" quests allowed later. `player_quests` holds
  the concrete instance (giver `npc_id`, target, state, rewards).
- **Objective types:**
  - **v1 — build first: kill / fetch / deliver** → resolve against combat, loot,
    exploration (systems we already have).
  - **later: find**, then **information objective** (completes when you *learn a
    tagged fact* — §7: via interrogation, a book, or an NPC telling you), then
    **social / deduction** quests (gather facts gated by Empathy/Politics/witnesses,
    then **Share** the conclusion to resolve). Designed now, built after the base loop.
- **Reward = Knowledge** (+ gold / trust / items): e.g. `goblin_culture +1`. Knowledge
  unlocks more facts → more reveal-requirements pass → more quests. The loop.
- Interrogation (§4) and books (§5) surface **clues = facts tagged to active quests**,
  so the mercy/interrogate/read loops feed quest progress.

### Multiple solutions & interconnected causes  (AGREED)

The two-layer design (§1) expressed at the quest level: a quest can have **more than one
valid solution**.

- **Surface path** — usually brute force (kill / clear). Always available; pays
  gold/loot. A player can solve every quest this way forever and never learn why.
- **Deeper path** — resolve the **root cause**. Opt-in, found via **hints only** (never
  directed), often reachable only if you *understand*. Pays a different currency
  (knowledge / standing / allies / a changed world-state) and usually avoids bloodshed.

Both complete the quest; neither is "the right answer."

**Interconnected causes.** The world is a system — a faction's problem often has its
cause in *another* faction, and understanding reveals the chain. Hints exist in the
world (tracks, a note, behaviour patterns); nothing forces the player to follow them.

**Canonical example — "Wolves are attacking the sheep":**
- *Surface:* kill the wolves → gold, done.
- *Hints:* the wolves' usual hunting grounds are blocked — goblins (or giant ants) have
  moved in.
- *Deeper:* deal with the **cause** — drive off / negotiate with / relocate the
  goblins/ants → the wolves return to their range and stop raiding the sheep. Reward:
  wolf standing / knowledge / maybe a wolf ally, and no massacre.

So **wolves are a parallel questline**, not a tutorial on-ramp (§11). Build note: v1
ships the **surface path** (kill/fetch/deliver above); the deeper root-cause path is the
understanding layer — authored now, built later.

---

## 9. Likely implementation surface  (TENTATIVE — not finalized)

Additive, grounded in current code. Details deliberately loose until we build.

Schema (sketch):
- Relationship, one table per scope (each holds the two axes `hostility, trust`;
  inner scopes only get a row once interacted with — else inherit the parent):
  - `rel_generic  (player_id, race, hostility, trust, PK(player_id,race))`
  - `rel_province (player_id, province_id, race, hostility, trust, PK(player_id,province_id,race))`
  - `rel_site     (player_id, site_id, hostility, trust, PK(player_id,site_id))` — race implied by site
  - `rel_npc      (player_id, npc_id, hostility, trust, PK(player_id,npc_id))` — per known individual
  - Effective attitude = weighted blend (person 8 / site 4 / province 2 / generic 1),
    inheriting parent values for scopes with no row (see §2). Derived stage from the blend.
- `npcs (id, player_id, race, profession, name, monster_id NULL, settlement_id NULL,
  site_id NULL, province_id NULL, state, created_at)` — the **one unified NPC table**
  (§8): village residents (seeded/generated) AND promoted monster individuals (created
  lazily on promotion; `monster_id` = the template they came from). Location = whichever
  of settlement/site/province is set. Anonymous spawns never get a row.
- `knowledge (player_id, topic, value, PK(player_id,topic))` — quest reward currency.
- Info model (§7):
  - `info_facts (id, subject_type ENUM(npc_type,npc,site,monster,event), subject_ref,
    category, content, requirement_json)` — authored facts; type-level seeded/in code,
    instance-level generated when an NPC is promoted. `requirement_json` = a boolean
    expression tree (`all`/`any`/leaf), evaluated recursively.
  - `player_knowledge (player_id, fact_id, state ENUM(remembered,shared), learned_at,
    shared_with NULL, PK(player_id,fact_id))` — the journal. (verified/common later.)
- `player_quests (id, player_id, race, template_key, target_json, state, reward_json, created_at)`
  — instances; templates live in PHP code.
- `characters.mercy TINYINT` — the stance toggle.
- New skills into `CHARACTER_SKILLS` (character.php): `linguistics/lang_*`, `empathy`,
  `survival`, `lore/lore_*`. (Global vs per-race granularity still OPEN — see §10.)

Handlers (sketch):
- `resolveFight()` gains a post-win branch: apply mercy (spare vs kill vs
  fanatic-forces-kill), start the mercy window, adjust Hostility.
- New `race.php`: relation read/update, stage thresholds, verb-gating.
- New `quests.php`: template instantiation, completion → Knowledge + Trust.
- Interrogate endpoint: consult active `player_quests`, roll for relevant intel.
- New `perception.php` (info model): `perceive($subject)` evaluates `info_facts`
  against the player's skills + blended Trust, returns the passing facts by channel,
  and logs newly-seen ones to `player_knowledge` (Remembered). Used by monster/site
  payloads and the pre-fight Tactics read.
- Monster/site payloads: call `perceive()` so visible info layers by relation + skills.
- Routes: `POST /api/combat/finish`, `POST /api/combat/interrogate`,
  `POST /api/race/talk`, `GET /api/knowledge` (journal), `POST /api/knowledge/share`
  (tell an NPC a fact), `GET /api/quests`, `POST /api/quests/complete` (names TBD).

Suggested build order (thin vertical slice, goblins only, to prove the loop):
1. `mercy` stance + two-axis `race_relations` + spare-lowers-Hostility (caps Neutral).
2. Mercy window with Finish (normal kill) + random fanatics.
3. Language skill + Interrogate (generic intel first).
4. Info model core (§7): `info_facts` + `player_knowledge`, `perceive()`, a handful
   of type-level goblin facts, encounter view layered by skills; Share action.
5. Unified `npcs` + home village residents (human quest-givers); `player_quests` with
   T1 **kill/fetch/deliver** templates + Knowledge/Trust rewards, tier-gated by stage.
6. Info-objective & social/deduction quests (learn a tagged fact / Share a conclusion);
   wire Interrogate + books to active quests.
Then expand race-by-race. (Contradictory truth / Verified / Common come later.)

---

## 10. Open questions / to decide later

- **Skill granularity**: global vs per-race vs hybrid (empathy/survival global,
  language/lore per-race). Leaning hybrid; not decided.
- **Interrogate tuning**: exact chance curve; does Empathy add lie-detection?
- **Flee & catch** (ideas.txt §3: civilians flee, pursue via dex check) — deferred,
  possibly folds into the mercy/temperament system.
- **Per-race mercy toggle** (spare goblins, cull wolves) — ship global first, this
  is a later UI expansion.
- **Richer opinion axes** (Respect/Gratitude/Hatred/Debt) beyond Hostility+Trust.
- **Scope tuning** — blend weights (8/4/2/1), propagation halving fraction, and
  when exactly an NPC gets "promoted" to a persistent person record.
- **Training model** — one slot vs a multi-slot queue; training durations per level.
- **Tutor/book tuning** — ceiling distributions per race, the diminishing-returns
  curve (gap fraction per session), and the book "readable literacy" threshold value.
- **Town-in-province generation** — how often provinces spawn a village/town hub,
  and what services they offer.
- **Info-model authoring & tuning** — how type-level facts are authored/seeded per
  race, and how facts are tagged to quests.
- **Quest/NPC tuning** — which professions & needs ship first; how home-village
  residents are seeded vs generated; own-race vs other-race quest-giver mix; how
  province town hubs are populated.
- **Where Talk/Trade/Quest UI lives** — extend the Exploration/delve flow vs a new tab.

---

## 11. Rejected / parked

- **Monsters approaching to TEACH the player their language** — parked 2026-07-24.
  No believable reason for a just-looted race to seek the player out and gift words.
  Language is player-earned instead (§5). NOTE: monster-initiated contact in general
  (quests, seeking help) is NOT rejected — welcome later once trust exists (§1).
- **Interactive mid-fight mercy interrupt** — parked in favor of the simpler
  post-fight stance + 30s window.
- **Wolf "primitive-language" on-ramp** — dropped 2026-07-24. No dedicated easy-language
  tutorial race; **goblins are the first understanding race**, discovered via
  writings/books/sparse notes/spoken words with **no push**. Wolves instead become a
  **parallel questline** (e.g. the sheep / blocked-hunting-grounds quest, §8) that shows
  off multiple-solution + interconnected-cause design.

---

## Decision log

2026-07-24:
- **Two-layer, hidden & opt-in design** (§1): a complete surface hack-and-slash
  (gold/gear) + a hidden understanding layer (info/allies/quests/story) reachable only
  by investing in language/skills. **NO directions** — no NPC pitch/quest-marker/nudge;
  discovery is intrinsic (grind→boredom→experiment) + environmental (consistent
  gibberish, untranslatable goblin books, mismatched observations). Two currencies so
  neither playstyle is "wrong." Accepted that fewer players find it; cues must still
  exist in-world ("no directions" ≠ "no cues"). Two is a **starting lens, not a wall** —
  long-term goal is a spectrum of emergent playstyles (trader, tactician, lorekeeper,
  manipulator, peacemaker…) from orthogonal systems; don't hard-wire the binary. ✔
- **Wolf on-ramp dropped; goblins are the first understanding race** (§1/§8/§11). No
  push — cues only: consistent spoken words, writings, books, sparse notes. Wolves
  become a parallel questline. ✔
- **Quests can have multiple solutions** (§8): a surface brute path (kill; always
  available; gold/loot) and an opt-in deeper root-cause path (hints only; different
  currency). The world is **interconnected** — one faction's problem is often caused by
  another. Canonical: wolves raid sheep because goblins/ants block their hunting grounds
  → kill the wolves OR fix the cause. v1 builds the surface path. ✔
- Two-axis relationship (Hostility vs Trust); sparing lowers Hostility only and
  **caps at Neutral**; friendship needs active help. ✔
- Relationship tracked at **four nested scopes** — person (×8) / site (×4) /
  province (×2) / generic (×1); effective = weighted blend / 15; inner scopes
  inherit parent when unset; deeds land full at the most-specific scope and halve
  outward (word spreads weakly). Person scope needs persistent NPC identity; build
  generic→province→site→person. ✔
- Mercy = post-fight stance toggle; sparing forfeits gold + loot-item roll,
  keeps skill training. ✔
- Unsparable races (undead/constructs) ignore mercy. ✔
- Some enemies resist mercy — **random per-encounter roll** for now. ✔
- Mercy window ~30s: **Finish = normal kill** (no betrayal penalty); Interrogate
  if language known; else it flees. ✔
- Interrogation requires language; yields a **mix** and has a **chance to return
  info relevant to the player's active quests**. ✔
- **Reciprocal mercy**: on player defeat, the race spares or finishes/robs the
  player based on its attitude (hostile = robbed/finished, neutral = spared,
  friendly = spared/helped); random no-mercy enemies mirror the fanatics. Mercy
  begets mercy. No permadeath. Tuning open. ✔
- **Language & skill training via tutors** (§5): home settlement = village hub;
  provinces may/may not have a town hub too; tuition is variable (gold and/or a
  quest — player may request a quest); training is a timed job with **one slot**. ✔
- **Tutor quality is semi-random with diminishing returns** (§5): each tutor has a
  semi-random teaching *ceiling* (goblins skew high for goblin-tongue if approached
  mildly, humans low, individual spread); a session adds based on the gap to that
  ceiling, so more knowledge = less gain, and fluency needs high-ceiling tutors.
  Replaces hard caps. ✔
- **Books** (§5): unlocked once language passes a mid literacy threshold; each book
  gives a small language bump + history/culture/folklore Knowledge + a chance at
  active-quest clues. ✔
- **Information model** (§7) is the perception framework, designed BEFORE quests
  (they consume it). Everything knowable = a **fact** on a subject, gated by a
  **boolean requirement** (AND/OR/nested — `all`/`any`/leaf tree) over skills +
  relationship; skills stay mechanical AND act as perception keys (per-channel). Facts are authored at **both type and instance**
  level. Revealed facts are logged to a **journal**; memory states = **Remembered +
  Shared** now (Shared = tell an NPC → world reacts); **Verified/Common + contradictory
  truth deferred** to a later layer. ✔
- **Village, NPCs, quest-givers & quests** (§8): ONE unified `npcs` table (village
  residents + promoted individuals); home village = own-race (human) quest source but
  **no hard lock** — other-race givers may appear initially; quest-givers generate from
  profession + needs, tier-gated by relationship stage; quests are procedural
  templates → instances, reward = Knowledge (+gold/trust/items); **v1 objectives =
  kill/fetch/deliver**, info-objective + social/deduction quests designed now but built
  after the base loop. ✔
- Monsters coming to **teach the player their language** parked (unbelievable);
  language is player-earned. Monster-initiated contact in general is fine later
  once trust exists — player initiates first contact for now. ✔
