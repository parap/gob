# Schema migrations

One forward-only file per change, named so they sort into the order they must run in:

```
0001_add_invite_used_at.sql
0002_drop_dead_location_tables.sql
```

`schema.sql` builds the **first** database and is never read again — MySQL runs its init
directory only while creating a volume that does not yet exist. So a column added only to
`schema.sql` reaches a fresh checkout and never reaches the deployment, and the mismatch
surfaces as a missing column on somebody's request rather than at deploy time.

Change both: `schema.sql` so a new database is correct from the start, and a migration here
so the existing one catches up.

```sh
docker exec gob_php php bin/migrate.php --status   # what is pending, changes nothing

dump=$(bin/backup-db.sh pre-deploy $(git rev-parse --short HEAD))
docker compose exec -T php php bin/migrate.php --dump="$dump"
```

Applying names the dump taken for it, and is refused without one on any database that is
not a throwaway `_test` schema: `git reset` returns the code, and nothing returns a column
a migration dropped. The name is what makes it a guarantee — the directory also holds
dumps taken for other changes, and age alone cannot tell those from a dump taken for this
migration. A named dump older than half an hour is refused too. `--status` reads the
ledger and is exempt.

`bin/deploy.sh` runs it on every deploy. Applied versions are recorded in
`schema_migrations`, and a file is recorded only once all of its statements have landed —
a version written down for a migration that did not finish would be skipped forever after,
leaving the schema wrong with nothing saying so.

There is no down-migration. Reversing a change is a new file that reverses it, because a
rollback script is written when the schema is healthy and run when it is not.
