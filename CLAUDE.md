# Goblin

A browser-based fantasy RPG. The hack-and-slash surface is a disguise: the real game is
that learning skills — language, empathy, observation, lore — changes what the same
monsters, places and events turn out to be. A proposal that makes combat richer but leaves
the player's model of the world untouched is off the premise. `ideas.md` holds the
direction, `TODO.md` what comes next.

## The checkout is the running game

`gob_php` and `gob_web` mount this directory at `/var/www/html`, read-write for PHP and
read-only for the proxy, and the frontend is vanilla JavaScript with no build step. A saved
file is live on the next request — no restart, no deploy step. On the server that request
may be someone playing.

- **Commit and push in the same task that edits.** Code serving players from no commit
  cannot be identified, reviewed, or returned to.
- A broken save is a broken game until the next one, so run the suite before moving on.
  Work too large to be seen half-finished belongs on a branch.

## Tests run on a development machine, not on the server

```
docker exec gob_php vendor/bin/phpunit
```

The container holds the PHP version the project requires and reaches both the database and
the running stack, so the suite runs there rather than on the host.

The deployment carries no development dependencies on purpose: `composer.json` requires
only php, `public/index.php` registers its own PSR-4 loader when `vendor/` is absent, and
the image has no composer. The command above therefore fails on the server with a missing
file rather than with a failing test, and **a change written directly in the server's
checkout is live and unverified**. Say so when handing it over, and run the suite from a
checkout at that same commit before building anything on top of it.

## Schema changes go through `db/migrations/`

`schema.sql` runs only while MySQL's volume is being created, so editing it changes nothing
on a database that already exists. A schema change reaches a running instance as a file in
`db/migrations/` and in no other way; its absence surfaces as a missing column on a
player's request rather than at deploy time.

Dump first, then migrate — in that order, because the dump is what the migration is
protected by:

```
bin/backup-db.sh pre-deploy $(git rev-parse --short HEAD)
docker compose exec -T php php bin/migrate.php
```

## Deployment runs from the laptop

`bin/deploy.sh` tests, dumps, moves the commit, migrates, and checks the result from
outside the server. It reaches the machine over the `oracle` ssh alias, which exists on the
laptop only, so running the script on the server itself fails. A change made directly in
the server's checkout still needs its migration and its commit; the script is what does
those in the right order.

## Secrets

- `GOB_INVITE_CODE` gates registration on the public name. Read the deployment's value in
  your own terminal, never through a session: whatever a session prints is written to its
  transcript and re-sent to the API on every later request, and the only remedy after that
  is changing the value.
- Database passwords come from `.env` and have no defaults. A clone that skips that step is
  refused at startup rather than coming up on a credential this public repository names.

## Do not import Dominions 6 data into the deployment

`tools/dom6/import.php` loads content that is © Illwinter Game Design. A laptop is private;
the deployment is served to anyone who finds the name, and the licence does not follow it
there.

## Shape of the code

- `src/Domain` — the pure rules: no database, no clock.
- `src/Repository` — SQL, one repository per aggregate.
- `src/handlers` — the procedural layer the API calls.
- `public/` — `index.php` routes `/api`, `js/` is the client.

No framework, no build step, no frontend package manager. Adding any of the three is a
design decision rather than a convenience: raise it before writing it.
