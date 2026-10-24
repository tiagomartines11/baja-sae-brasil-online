# Results snapshots

A record of what every results page computes today, so that a change which
alters a published result cannot slip through unnoticed.

`baja-php/scripts/results-snapshot.php` runs the real results code against a
database and writes what it produced into `baja-php/tests/results-snapshots/`,
one JSON file per page, premiação or prova. `--check` does the same into a
temporary folder and fails if anything differs from the committed files. CI
runs `--check` on every PR against the dev seed.

## What it guards

| Change coming | What must stay equal | Snapshot files |
|---|---|---|
| BAJAAPP-54: one shared results builder replacing `resultados/prova.php`, `prova_juiz.php`, `prova_debug.php` and `Baja\Premiacao\Resolver` | every public results table, every premiação | `<ID>.json`, `<ID>.torneio.json`, `premiacao.<ID>.json` |
| BAJAAPP-35: replacing `eval()` in `Input::solveFormula` | every team's vars and pontos | `formulas.<PROVA>.json` |
| BAJAAPP-42 and the future API: what the public sees *during* an event | the spoiler view | `<ID>.spoilers.json` |

For every event with `finalizado = 1` (14 of the 17 events in the seed):

- **`<RESULTADO>.json`**: `resultados/prova.php?id=<RESULTADO>`, as the public
  sees it. For a tournament resultado this is the redirect to `torneio.php`.
- **`<RESULTADO>.torneio.json`**: `resultados/torneio.php`, for resultados
  with `colunas.type == "tournament"`.
- **`<RESULTADO>.spoilers.json`**: `prova.php` again, for resultados whose
  columns have a `val_spoilers`, with the event shown as running
  (`spoilers = 1`, `finalizado = 0`).
- **`premiacao.<ID>.json`**: `Baja\Premiacao\Resolver::resolve()` for each
  premiação row.
- **`formulas.<PROVA>.json`**: each team's vars and pontos, recomputed by
  `Input::updateVars()` and `Input::updatePontos()`.

Not snapshotted: `resultados/prova_juiz.php` and `resultados/prova_debug.php`.
Run without a forum login, `prova_juiz.php` shows the same rows as
`prova.php`. Its one difference, the `authorized_users` path that lets
named judges see past spoilers, needs a logged-in user. That path is
covered by the unit tests of the shared results builder,
`baja-php/tests/Unit/Results/ViewerContextTest.php`.
`prova_debug.php` is being deleted (BAJAAPP-57).

## How it works

**Pages run unchanged, the way nginx runs them.** Each page runs in its own
PHP process, because the pages call `exit` and declare functions. The page
file is the main script, with `scripts/results-snapshot/page-prepend.php` as
`auto_prepend_file`. That file stands in for what
`baja-infra/nginx/templates/baja-app-resultados.conf.template` passes:
`REDIRECT_EVENT`, `SKIP_AUTH=1` and `?id=`. It then loads `src/bootstrap.php`
as nginx does, with `ENV=prod` so errors are logged, not displayed. Warnings,
notices and fatal errors are recorded on the side. No results code is
touched.

**Only the results are kept.** The HTML is reduced to its results table(s).
Each row maps a column header to the cell's inner HTML, with whitespace
normalised, in page order. A `<td>` or `<tr>` class is kept under
`td.class` / `tr.class` when present: that is where the gold, silver and
bronze medals and the tournament winner are. The menu, header, footer,
scripts and stylesheets are ignored. So are the clock-driven parts under the
table: `Última Atualização: [timestamp]`, `Tempo de Prova: [elapsed]`. A
changed cell, row order, highlight, medal or column is a difference; a
change to the page around the table is not. `tests/ResultsSnapshot/Unit/
TableExtractionTest.php` shows both.

**A page that dies is recorded as it is.** A page that dies on a fatal
error shows the public no results, so the error is part of its result.
`error` holds it as the error class and message, without file or line:

```
"error": "Error: Call to a member function getStatus() on null"
```

The location is left out because any edit above that line would move it,
and a refactor moves it to another file, without the page's result
changing. `error` is `null` for a page that ran to the end. When such a bug
is fixed, the page's tables appear in the snapshot and `error` becomes
`null`, and the diff shows both.

**PHP warnings are not compared.** Warnings, notices and deprecations are
not in the snapshot files and never fail a run. They carry a file and line,
so any edit moves dozens of them. The refactors should also be free to make
them disappear without a snapshot update. To see them, use `--warnings`
(below). It lists every distinct warning with its file and line, grouped by
message, with the snapshots that raise it.

**It never writes to the database.** Every connection the tool opens (its
own and every page's and job's) is switched to
`SET SESSION transaction_read_only = ON` before anything runs. Any write
would fail with MySQL error 1792, and that failure would land in the
snapshot. In CI the tool connects as a MySQL account that only has SELECT.
`tests/ResultsSnapshot/Db/NoWritesTest.php` checksums every table before and
after a run.

- *Spoiler view:* the event is loaded, its two flags are flipped on the
  in-memory object, and that object is placed in
  `EventoQuery::getCurrentEvent()`'s cache, which is how every page asks
  about spoilers. Nothing is saved (`Capture/DuringEventView.php`).
- *Formulas:* `updateVars()` and `updatePontos()` change the `Input` objects
  in memory and are never saved. `Prova::updateTotals()` (the min/max over
  all teams) is **not** called, because it can only run by saving.
  `updatePontos()` uses the totals as stored. The totals involve no
  `eval()`, and a vars change that would move them already shows in the
  same file.
- `differs_from_stored` lists the teams whose stored vars/pontos are not
  what the formulas give today. The pages show the stored values, so these
  are the teams whose published result would change the next time anyone
  saved an input of that prova.

**It is deterministic.** Lists are in page order or sorted by id. Paths and
the clock are removed, and number printing is pinned (`precision`,
`serialize_precision`). Running `--update` twice gives no diff.

The code is under `baja-php/src/Baja/ResultsSnapshot/`:

| Folder | What is there |
|---|---|
| `Tasks/` | the list of snapshots (`Catalogue`), one class per kind |
| `Capture/` | starting the PHP processes, recording errors, read-only, spoiler view |
| `Html/` | reading tables out of a page |
| `Jobs/` | premiação and formulas, run inside `scripts/results-snapshot/job.php` |
| `Files/` | the snapshot folder, comparing, the readable diff |

## Running it in the dev container

From `baja-infra/`:

```
docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/results-snapshot.php --check
```

The run takes about 10 seconds. It must say `Results snapshots match`. If it
does not, it lists what differs, file by file, as `before -> now`:

```
22BR/22BR_GER.json
    tables[0].rows[7] (# 9)["Dinâmicas"]: "128.69" -> "134.79"
    tables[0].rows[7] (# 9).Total: "<b>598.85</b>" -> "<b>604.95</b>"
```

Rows are named after the team (`# 9` is car 9). The fresh files are left in
the temporary folder it names, for `diff -r`.

`--check` compares against your **dev database**, so it only matches if that
database is the seed. Data you changed locally (a test save on a judge page)
shows as a difference. `docker compose down -v && docker compose up -d`
gives you the seed back; see the notes in `dev-workflow.md` about what that
resets.

To accept intended changes:

```
docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/results-snapshot.php --update
git add baja-php/tests/results-snapshots
```

Options: `--events 22BR,23SU` limits to some events (and compares only
those), `--dir <path>` uses another snapshot folder, and `--jobs <n>` sets
how many pages run at once (default 4).

To list the PHP warnings the pages raise today:

```
docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/results-snapshot.php --warnings
```

```
PHP warnings (report only, never compared): 21 distinct, in 73 snapshots.

Warning: Undefined property: stdClass::$highlight in resultados/prova.php:78   (55 snapshots)
    22BR/22BR_ABS.json
    ...
Warning: Undefined array key "overlay" in resultados/torneio.php:23   (5 snapshots)
    23SU/23SU_SPR.torneio.json
    ...
```

On its own, `--warnings` builds everything, prints the report and writes
nothing. Added to `--check` or `--update`, it prints the report first. Either
way it exits 0 unless the `--check` itself fails.

The tool's own tests:

```
docker compose exec baja-app vendor/bin/phpunit --testsuite results-snapshot
docker compose exec baja-app vendor/bin/phpunit --testsuite results-snapshot-db
```

The first needs no database. The second checks that a full run changes no
table, and is skipped where there is no database.

## Reviewing an accepted change

A PR that changes results code and ran `--update` has its result changes in
the diff of `baja-php/tests/results-snapshots/`. That diff is the part to
review:

1. `git diff --stat -- baja-php/tests/results-snapshots` shows which events
   and pages changed. A refactor that should change nothing must have an
   empty stat.
2. For each changed file, the diff shows exactly which cell of which team
   changed. Ask whether each one is what the PR says it does. A fix for a
   position bug should move positions in that resultado and nothing else. A
   fix for a warning changes no snapshot at all; compare `--warnings` before
   and after instead.
3. Changes in `formulas.*.json` mean computed numbers changed. Unless the PR
   is about a formula, that is a regression, however small.
4. Re-run `--check` locally on the PR branch; CI does too.

## Pre-release check against a copy of production

The committed snapshots cover the seed. Before a release, the same tool can
compare the release against **real data**. It takes a snapshot with the
release that is live now, then checks the candidate against it, on staging,
against a copy of the production database. The tool only reads, and any
write fails on its read-only session. For extra safety, give it a MySQL
account that only has SELECT, as below.

On the staging server, from the repository's `baja-infra/` folder, with the
production copy loaded:

```
# 0. Once: a read-only MySQL account for the tool (pick your own password).
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE USER IF NOT EXISTS snapshot@\"%\" IDENTIFIED BY \"change-me\"; GRANT SELECT ON baja_resultados.* TO snapshot@\"%\";"'

# 1. The code production runs now (its tag or commit).
git fetch --tags
git checkout <live-release-tag>
docker compose exec --user "$(id -u):$(id -g)" baja-app composer install --no-dev --no-interaction

# 2. Snapshot what it computes, into a scratch folder (not the committed one).
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me \
    baja-app php scripts/results-snapshot.php --update --dir /tmp/results-before

# 3. The release candidate.
git checkout <candidate-branch-or-tag>
docker compose exec --user "$(id -u):$(id -g)" baja-app composer install --no-dev --no-interaction

# 4. Check it against the snapshot of step 2. Exit code 0 = no published result changes.
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me \
    baja-app php scripts/results-snapshot.php --check --dir /tmp/results-before
```

Things to keep in mind:

- `/tmp/results-before` lives inside the `baja-app` container. Do not
  recreate the container between steps 2 and 4: `docker compose up -d`
  after a compose change would do that. If you must, `docker cp` the folder
  out first.
- The data must not change between steps 2 and 4. On a static copy it does
  not. Do not point this at the live production database during an event.
- By default every event with `finalizado = 1` is covered. Add
  `--events 26BR` to limit it, or to include an event that is not finished.
- Differences the release *should* make (a bug fix) show up here like any
  other. Compare them with what the release notes promise.
- `--dir` folders hold real team names and scores, so treat them like the
  data they come from. They are not for committing.

## Adding newer events to the seed

The seed `baja-infra/mysql/init/05-seed-baja-resultados.sql` holds public
results data only (evento, equipe, prova, input, resultado, tournament,
premiacao). No personal data and no other tables belong in it: the repository
is public.

1. Add the event's rows for those tables to `05-seed-baja-resultados.sql`.
   The event needs `finalizado = 1` to be snapshotted.
2. Reload the dev database (`docker compose down -v && docker compose up -d`
   from `baja-infra/`).
3. Run `--update`. Only new files under the new event's folder should
   appear. If files of other events changed, the seed edit touched them.
4. Commit the seed and the snapshots together.

CI loads `01`, `02`, `05` and `06` from `baja-infra/mysql/init/` (see the
`results-snapshots` job in `.github/workflows/ci.yml`), so a seed change is
picked up there with no other change.
