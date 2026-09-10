# SQLite data directory (outside public/)

Multihost deploy rsyncs **`public/`** to the vhost docroot. The live database must **not** live under `public/`.

Default path (PHP): `{repo}/db/doj_cases.db` via `Project1960\Config::databasePath()`.

| Env | Purpose |
|-----|---------|
| `DATABASE_PATH` | Absolute or repo-relative path to SQLite file |
| `DATABASE_NAME` | Filename under `db/` when `DATABASE_PATH` unset (default `doj_cases.db`) |

**Deploy / sync:** keep `db/` persistent (same idea as `public/uploads/` on other multihost sites). Do not wipe `db/` on deploy. Copy NewDev `doj_cases.db` here during O3; never commit the live DB.
