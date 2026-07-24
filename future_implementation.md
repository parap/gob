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

## 2. Relationship model — TWO axes, not one  (KEY DECISION)

A race does not have a single "standing" number. It feels (at least) two separate
things about the player, moved by different inputs:

- **Hostility / Fear** — how much the race attacks on sight.
- **Trust / Liking** — how much the race helps / opens up to the player.

Rules:
- **Sparing lowers Hostility only.** Robbing a goblin and leaving it alive does
  NOT make goblins like you — it teaches them you're not a pure exterminator.
  → Sparing can only move a race from *"kill on sight"* to *"wary coexistence"*.
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

## 5. Language learning

- Language is trained by **contact / help**, NEVER by fighting (fighting trains
  combat skills only).
- Likely sources (to finalize later):
  1. Rare **phrasebook / captured writings** loot → jump-start first words.
  2. Contact with a race once Hostility is low enough (they'll teach words).
  3. Possibly a neutral intermediary (ideas.txt §2 ladder: animals → wolves → …).
- First concrete payoff of language = **Interrogate** (§4), long before "be friends".

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

## 7. Quests & Knowledge  (from ideas.txt §4, to detail later)

- **Tiered quest templates** shared across races (same structure, different text):
  T1 kill/fetch/repair/find → T2 save child/heal sick/catch thief/find lost →
  T3 settle dispute/reconcile clans/solve murder → T4 change policy/alliance/depose.
- **×100 content via professions + needs**: tag NPCs Hunter/Shaman/Chief/Merchant/
  Child/Guard/Farmer; each auto-generates requests from needs (Food/Medicine/
  Protection/Knowledge/Revenge/Mate/Religion).
- **Reward = Knowledge**, not (only) gold: e.g. `goblin_culture +1`,
  `goblin_history +2`. Knowledge unlocks new dialogue/checks.
- Interrogation (§4) reads from the player's active quests → quests and the
  mercy/interrogate loop reinforce each other.

---

## 8. Likely implementation surface  (TENTATIVE — not finalized)

Additive, grounded in current code. Details deliberately loose until we build.

Schema (sketch):
- `race_relations (player_id, race, hostility, trust, stage, PK(player_id,race))`
  — the two axes + derived stage.
- `knowledge (player_id, topic, value, PK(player_id,topic))` — quest reward currency.
- `player_quests (id, player_id, race, template_key, target_json, state, reward_json, created_at)`
  — instances; templates live in PHP code.
- `characters.mercy TINYINT` — the stance toggle.
- New skills into `CHARACTER_SKILLS` (character.php): `linguistics/lang_*`, `empathy`,
  `survival`, `lore/lore_*`. (Global vs per-race granularity still OPEN — see §9.)

Handlers (sketch):
- `resolveFight()` gains a post-win branch: apply mercy (spare vs kill vs
  fanatic-forces-kill), start the mercy window, adjust Hostility.
- New `race.php`: relation read/update, stage thresholds, verb-gating.
- New `quests.php`: template instantiation, completion → Knowledge + Trust.
- Interrogate endpoint: consult active `player_quests`, roll for relevant intel.
- Monster/site payloads: layer visible info by relation + skills.
- Routes: `POST /api/combat/finish`, `POST /api/combat/interrogate`,
  `POST /api/race/talk`, `GET /api/quests`, `POST /api/quests/complete` (names TBD).

Suggested build order (thin vertical slice, goblins only, to prove the loop):
1. `mercy` stance + two-axis `race_relations` + spare-lowers-Hostility (caps Neutral).
2. Mercy window with Finish (normal kill) + random fanatics.
3. Language skill + Interrogate (generic intel first).
4. `player_quests` + one T1 template + Knowledge reward + Trust-from-help.
5. Wire Interrogate to active quests.
Then expand race-by-race.

---

## 9. Open questions / to decide later

- **Skill granularity**: global vs per-race vs hybrid (empathy/survival global,
  language/lore per-race). Leaning hybrid; not decided.
- **Interrogate tuning**: exact chance curve; does Empathy add lie-detection?
- **Flee & catch** (ideas.txt §3: civilians flee, pursue via dex check) — deferred,
  possibly folds into the mercy/temperament system.
- **Per-race mercy toggle** (spare goblins, cull wolves) — ship global first, this
  is a later UI expansion.
- **Richer opinion axes** (Respect/Gratitude/Hatred/Debt) beyond Hostility+Trust.
- **Where Talk/Trade/Quest UI lives** — extend the Exploration/delve flow vs a new tab.

---

## 10. Rejected / parked

- **Monsters approaching to TEACH the player their language** — parked 2026-07-24.
  No believable reason for a just-looted race to seek the player out and gift words.
  Language is player-earned instead (§5). NOTE: monster-initiated contact in general
  (quests, seeking help) is NOT rejected — welcome later once trust exists (§1).
- **Interactive mid-fight mercy interrupt** — parked in favor of the simpler
  post-fight stance + 30s window.

---

## Decision log

2026-07-24:
- Two-axis relationship (Hostility vs Trust); sparing lowers Hostility only and
  **caps at Neutral**; friendship needs active help. ✔
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
- Monsters coming to **teach the player their language** parked (unbelievable);
  language is player-earned. Monster-initiated contact in general is fine later
  once trust exists — player initiates first contact for now. ✔
