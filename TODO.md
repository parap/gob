# Goblin — TODO

Running list of loose ends and next steps. See `ideas.md` (raw brainstorm) and
`future_implementation.md` (the agreed design + build order).

## Refactor loose ends
- [ ] **`auth.php` → `PlayerRepository` / `SessionRepository`** for 100% OOP coverage.
      It's currently thin session/player glue (register/login/logout + tokens), so
      low priority — everything else already delegates DB access to repositories.
- [ ] **Prune dead tables** `locations` / `location_stages` / `player_locations`
      from `schema.sql` (unused since the province world replaced the old flat
      exploration; `explore.php` was already deleted).

## Gameplay
- [x] **Starter-gear auto-equip** on character creation — `CharacterRepository::ensure()`
      now equips `STARTER_ITEMS` via `ItemRepository::equipIfFree()`, so a new hero
      spawns wearing the Rusty Sword + Leather Cap instead of losing the first
      goblin fight bare-handed.
- [x] **Mercy slice, build-order steps 1–2** (`future_implementation.md` §2/§3):
      mercy stance, two-axis relationships blended over generic/province/site,
      spare-lowers-Hostility capped at Neutral, the 30s mercy window with
      Finish, and the random fanatic roll.
- [x] **Step 3a — language skill + tutors.** Languages are ordinary skills named
      `lang_<race>`, never trained by use. Scholar Yves teaches Goblin tongue for
      gold + time: one training slot, a semi-random per-tutor ceiling, and each
      session closing 35% of the gap to it, so he tops out around "broken" and
      real fluency will need goblin tutors. Nothing advertises it — his row just
      says "Ask", like everyone else's.
- [x] **Step 3b — Interrogate.** Third button in the mercy window, shown only
      if you know the tongue. Yields a degraded rendering of what they say
      (resolving further as the language grows), a ~38%-at-broken chance of
      giving up a hidden site their own kin hold, and sometimes a buried stash.
      Questioning consumes the window and still counts as sparing them.
- [x] **Step 4 (core) — the info model.** `info_facts` + `player_knowledge`,
      a recursive boolean requirement evaluator (`all`/`any`/leaf over skill /
      substat / trust / hostility), `perceive()`, eleven authored goblin facts
      across four channels, encounter views layered by what the player can
      make out, and a Journal tab showing "n of N known" per subject.
- [ ] **Share (§7) — deliberately not built.** The state exists in the schema
      (`player_knowledge.state`, `shared_with`) but there is no action, because
      Shared is defined by its consequences — tell a goblin their chief lies,
      tell a lord where the artifact is — and inventing those is narrative
      design, not plumbing. Build it with the first NPC who should react.
- [ ] **Interrogation lore still isn't fact data.** `Interrogation::LINES` is a
      separate hardcoded pool that vanishes once read, and `quest_relevant`
      only *flags* intel rather than advancing anything. Now that facts are
      data, prisoner speech should become facts tagged to the quest they bear
      on — that is what turns interrogation into a quest objective.
- [ ] **Only goblins have lines.** `Interrogation::LINES` covers one race; every
      other falls back to a single "nothing worth keeping" string. Unreachable
      today (the scholar teaches only Goblin), but it gates adding any second
      language.
- [x] **Promotion + goblin tutors — the loop closes.** Spare a goblin and
      question it and it stops being a spawn: it takes a name, gets a row in
      `npcs`, and gains the person scope (`rel_npc`, x8). One per race per
      province. It teaches its own tongue at native ceilings (65-95), but only
      once its people read Neutral — which sparing can reach and nothing else
      can. Measured end to end: lang_goblin 8 -> 31, past anything the village
      scholar could offer, unlocking speech facts gated at 25.
- [ ] **Reciprocal mercy** (§3, designed but not built): on a *loss*, the race's
      attitude should decide the outcome — hostile robs you, neutral spares you,
      friendly patches you up. Currently every defeat is the old flat 1 HP.
- [ ] **Nothing yet moves an individual's own standing.** `rel_npc` rows are
      written by the blend's propagation, but no deed targets a known person
      specifically — meet Yigna again and she is just her tribe's average. Needs
      deeds that know which individual they happened to (gift, repeat spare).

## Tuning (numbers picked to ship, all open per `future_implementation.md` §11)
- [x] **Race taxonomy split into three axes.** `monsters.race` used to mix
      peoples, natures and body types (24 monsters had the "race" `humanoid`).
      Now `race` = the people, `nature` = mortal/beast/magical/undead/construct/
      plant, `nation` = the country where the name implies one. Sparability keys
      off nature; `race = 'none'` (statues, risen corpses, walking vines) tracks
      no relationship at all. Curated in `tools/dom6/identity.php`.
- [ ] **Nations are data only.** Every human shares one standing regardless of
      country. Splitting them means a nation scope nested under race (inheriting
      from it, deeds halving outward) — deferred deliberately, not forgotten.
- [ ] `Relationship::START_HOSTILITY` per people — 16 named, everything else
      falls back to 75.
- [ ] Stage thresholds (70 / 40 hostility, 25 / 60 trust), the spare drop (−6)
      vs. kill rise (+2), and the 25% fanatic rate. All are single constants.
- [ ] **`alignment` is still derived from the Dominions `holy` flag**, which is
      why the Mad Cultist is "good". Lower priority — nothing reads alignment
      mechanically yet, but it shows in the UI badges.

## Housekeeping
- [ ] **Refresh project memory** — it hasn't been updated since the RPG-layer slice
      or the OOP refactor; record the `Domain` / `Repository` architecture so future
      sessions follow the pattern.
- [ ] **Remove dev test users** from the DB volume created while testing:
      `vtest1..3`, `eartest`, `eartest2`, `itemtest`, `settletest`, `ooptest`,
      `loototest`, `questoop`, `worldoop` (and `alex` / `gareth` are the intended
      test accounts to keep).
