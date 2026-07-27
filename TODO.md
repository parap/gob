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
- [ ] **Next slice (per `future_implementation.md` build order):** mercy stance +
      the two-axis relationship model + the Spare action. Now much easier on the
      repository foundation.

## Housekeeping
- [ ] **Refresh project memory** — it hasn't been updated since the RPG-layer slice
      or the OOP refactor; record the `Domain` / `Repository` architecture so future
      sessions follow the pattern.
- [ ] **Remove dev test users** from the DB volume created while testing:
      `vtest1..3`, `eartest`, `eartest2`, `itemtest`, `settletest`, `ooptest`,
      `loototest`, `questoop`, `worldoop` (and `alex` / `gareth` are the intended
      test accounts to keep).
