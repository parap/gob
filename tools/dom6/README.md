# Dominions 6 data importer

Seeds extra **monsters** and **items** into the game from the community
[Dominions 6 Inspector](https://larzm42.github.io/dom6inspector/) data.

## Run

```bash
php tools/dom6/import.php
```

Connects to the dev DB (defaults to the docker-mapped MySQL on `127.0.0.1:3307`,
overridable via `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS`). Re-runnable —
it upserts, so running it again refreshes the imported rows.

- Imported **monsters** use ids `>= 1000`, **items** ids `>= 2000` — they never
  collide with the hand-authored seeds in `schema.sql`.
- Source CSVs are kept in `gamedata/` so we can mine more columns later without
  re-downloading. Descriptions are fetched per-entity from the live site at
  import time (`unitdescr/<id>.txt`, `itemdescr/<name>.txt`).

## Mapping (approximate — "suitable stats", not a faithful sim)

| Dominions | GOB |
|---|---|
| unit `hp` × 2.5 | monster `hp` |
| unit `att` | monster `attack` |
| unit `def` ÷ 3 | monster `defense` |
| unit `prot` ÷ 3 | monster `protection` |
| unit `str` ÷ 5 | monster `penetration` |
| unit `basecost` (×2, sentinels ignored) | monster `reward_gold` |
| item `type` | slot (`misc` → ring/bracelet/glasses/banner) |
| item `constlevel` | rarity |
| item magic columns + linked weapon/armor | item bonuses |

## ⚠️ IP note

This data is **Dominions 6 content, © Illwinter Game Design**. Names and
descriptions are their intellectual property. Fine for private/hobby use, but
**before making this game public, replace or genericize the imported names and
descriptions** (or remove the imported rows). Decision deferred for now.
