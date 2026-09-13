# Goblin

A browser-based fantasy RPG in the spirit of *Mother of Learning*. You start
out playing an ordinary hack-and-slash — kill monsters, grab loot — but the
real game is about **changing your model of the world**: as you learn skills
(language, empathy, observation, lore…), the same "monsters" and places reveal
deeper layers, and you can talk, trade, and side with the creatures you used
to just fight.

See `ideas.md` for the full design direction.

## Stack

- **Backend:** PHP (JSON REST API under `/api`)
- **Database:** MySQL / MariaDB
- **Web server:** Caddy (reverse proxy + automatic HTTPS)
- **Frontend:** vanilla JavaScript (no framework, no build step)

## Tests

PHPUnit, run inside the app container — the container has the PHP 8.2+ the
project requires, and reaches both the database and the running app:

```
docker compose up -d
docker exec gob_php vendor/bin/phpunit
```

Four layers, each tested where its behaviour actually lives:

- `tests/Domain` — the pure rules. No database, no clock.
- `tests/Repository` — real SQL against `gob_test`, a throwaway schema built
  from `schema.sql`. Each test deletes the players it created; the seeded
  catalogue survives. The base class refuses any database whose name does not
  end in `_test`.
- `tests/Handler` — the procedural layer, called as plain functions.
- `tests/Api` — over HTTP against the running stack, for refusals a handler
  delivers by ending the request. These use the development database and
  remove the throwaway players they register; they skip when nothing is
  serving.

`gob_test` is created with the database on a fresh volume. On a volume that
predates it:

```
docker exec gob_db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e \
  "CREATE DATABASE IF NOT EXISTS gob_test; GRANT ALL ON gob_test.* TO '"'"'gob'"'"'@'"'"'%'"'"';"'
```

## Running it

```bash
cp .env.example .env && chmod 600 .env     # then fill in the two passwords
docker network create edge                  # once per machine
docker compose up -d
```

The game is then at http://localhost:8090, phpMyAdmin at http://localhost:8091.

Both passwords come from `.env` and have no defaults, so a clone that skips that step is
refused at startup rather than coming up on a credential this public repository already
tells the world.

## Deployment

`bin/deploy.sh` puts the current commit on the server and checks the result from this
machine. The game runs there behind a shared proxy that terminates TLS for every site on
the host — see `~/edge/README.md`. This project publishes nothing to the internet: its
ports bind the loopback and are reached over `ssh -L`.

`schema.sql` and the test-database file are mounted into MySQL's init directory, which runs
**only on a volume that does not yet exist**. The first deploy therefore builds the schema
and seeds the catalogue; every change after that needs a migration, because a second deploy
will not re-read them. The world itself is not seeded — provinces, settlements and sites are
created per player at registration.

Do not run `tools/dom6/import.php` against the deployment. Its data is Dominions 6 content,
© Illwinter Game Design, and the game is public once it is served.

## Status

Early development.
