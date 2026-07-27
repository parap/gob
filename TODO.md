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
- [ ] **Step 3 — language skill + Interrogate.** The mercy window currently
      offers only Finish or letting them crawl off; Interrogate is what makes
      sparing pay a *different* currency instead of just costing loot. Needs a
      `lang_goblin`-style skill first (§5), so it lands with tutors.
- [ ] **Reciprocal mercy** (§3, designed but not built): on a *loss*, the race's
      attitude should decide the outcome — hostile robs you, neutral spares you,
      friendly patches you up. Currently every defeat is the old flat 1 HP.
- [ ] **Person scope** `rel_npc` (×8): the blend already reserves its weight and
      inherits from site when absent, so adding it is additive. Blocked on
      promoting a spared individual to a persistent NPC (§10).

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
