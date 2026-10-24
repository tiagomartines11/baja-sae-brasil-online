# Resultado config check

`baja-php/scripts/check-results-configs.php` finds resultado configs that are
broken in the **data** (the `colunas` JSON typed in *Administrar resultados*),
not in the code. Since BAJAAPP-61 a team with no number to rank by gets no
position (docs/results-rules.md, R3), so a broken config now shows "–" in the
`Pos` column instead of a made-up ranking. This tool lists those configs, and
writes the fix for the one case that is mechanical.

It only reads. Like `results-snapshot.php` it switches its database session
to read-only before anything runs, and `tests/ResultsCheck/Db/` checks that a
run changes no table. The fixes it proposes are SQL in a file, for a person to
read and run.

## What it checks

For every resultado of every event (or of `--events`):

1. **Every formula, for every team**, run by the real results builder:
   each column's `val` (each line of a list), `highlight`, `val_spoilers`,
   `val_api`, and the `tiebreak`. A formula that raises an error leaves its
   cell empty on the page; the report gives the error and how many teams it
   hits. Typical errors:
   - `TypeError: Unsupported operand types: string + float`: text joined with
     `+` (`'<strong>'+ROUND($PTS,2)+'<strong>'`). PHP joins text with `.`.
   - `ValueError: max(): Argument #1 ($value) must contain at least one element`:
     PHP 8's `max()`/`min()` of an empty list.
   - `ParseError`, `Error: Undefined constant`: typos.
2. **The ranking settings**: a `pos` that names no column and no variable, a
   `Pos` column with no `pos`, a `pos_order` other than `asc`/`desc`, and a
   `pos` that leaves every team without a position.
3. **Columns empty for every team** although no formula raised an error.
   Often a variable spelled differently from the prova's, sometimes fine (no
   team was given a penalty).

It also runs every **prova**'s formulas (`params`: `vars`, `min_max`,
`pontos`) for every team, and reports their errors. Those are report-only:
saving a prova recomputes every stored point (BAJAAPP-56), so a person decides.
Tournament resultados (`"type": "tournament"`) have no columns and are skipped.

Exit code 0 whenever the report was made, whatever it found; 2 when it could
not run (bad options, no database).

## The report

```
=== 26BR ===

26BR_REL  Relatório de Projeto  (49 teams)
  Formula raises an error
    column "Pontos" val
      formula: '<strong>'+ROUND($RELPTS,2)+'<strong>'
      error:   TypeError: Unsupported operand types: string + float   (49 of 49 teams)
      fix:     '<strong>'.ROUND($RELPTS,2).'</strong>'
  Ranking config
    pos "Pontos" has no number for any team, so every team shows "–" (it is empty for every team)
```

A `fix:` line means the fix is in the `--sql` file. `no fix:` means the
mechanical rewrite was tried but the formula still fails, so it needs a
person. Everything else needs a person too.

## The fixes (`--sql <file>`)

Only one case is fixed by the tool: `+` with a string literal right before or
after it becomes `.`, and in such a formula a tag opened twice and never
closed (`'<strong>'…'<strong>'`) gets its second one closed. `+` between
numbers, `+` inside quotes, and text that is a number (`'5'`) are left alone.
The rewritten config is then run again for every team; a rewrite that still
fails, or that makes a later column fail (a column reads earlier ones,
`$Pontos`), is not proposed.

The file has one `UPDATE` per resultado, in one transaction. Each one:

- is preceded by a comment with the old and the new formula;
- keeps the current columns as backup `-1`, moving `-1`…`-4` to `-2`…`-5` and
  keeping named backups, exactly as the judge page does when someone saves
  (*Backups Últimas Alterações* in `juiz/resultado.php`). Undoing a fix is
  loading backup `-1` there and saving;
- only applies if the columns are still exactly what the report saw
  (`AND colunas = CAST('<the JSON it read>' AS JSON)`), and is followed by a
  `SELECT` that shows `updated = 1` if it applied, `0` if not.

## Runbook (BAJAAPP-62)

Production has more events than the seed, so the list must come from a copy
of production. Paths below assume the repository at `~/baja-sae-brasil-online`,
as in `server-migration.md`.

### 1. On staging, with a fresh copy of production and `develop` checked out

From `baja-infra/`:

```
git fetch origin
git checkout develop
git pull
docker compose exec --user "$(id -u):$(id -g)" baja-app composer install --no-dev --no-interaction

docker compose exec --user "$(id -u):$(id -g)" baja-app \
    php scripts/check-results-configs.php --sql results-config-fixes.sql > ~/results-config-report.txt
```

The SQL lands in `baja-php/results-config-fixes.sql`. Read both files. Every
`UPDATE` comment must show an old and a new formula that mean the same thing.

### 2. On production

Copy `results-config-fixes.sql` to the production server's home folder (e.g.
`scp`). Then, from `~/baja-sae-brasil-online/baja-infra`:

```
set -a; source ~/baja-sae-brasil-online/baja-infra/.env; set +a

# a. Back up the resultado table first.
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction baja_resultados resultado \
  > ~/resultado-before-bajaapp62.sql
ls -l ~/resultado-before-bajaapp62.sql     # must not be empty

# b. Apply the fixes. Every line it prints should say updated = 1.
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" baja_resultados < ~/results-config-fixes.sql
```

A `0` means that resultado changed on production after the copy was taken:
it was left alone, and the report below still lists it.

Then run the report on production, read-only. Production runs `master`, which
does not have the tool yet, so it runs from a separate checkout of `develop`
in a throw-away container. The live `baja-app` container and the deployed
code are not touched:

```
cd ~/baja-sae-brasil-online
git fetch origin develop
git worktree add --detach ~/baja-check origin/develop
cd baja-infra

# Its own vendor/ folder, inside ~/baja-check only.
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm --no-deps \
  --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
  -v ~/baja-check/baja-php:/check -w /check --entrypoint composer \
  baja-app install --no-dev --no-interaction --no-progress

# c. The report. The errors fixed in b must be gone.
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml run --rm --no-deps \
  --user "$(id -u):$(id -g)" \
  -v ~/baja-check/baja-php:/check -w /check --entrypoint php \
  baja-app scripts/check-results-configs.php > ~/results-config-report-after.txt

# Clean up the checkout.
cd ~/baja-sae-brasil-online
git worktree remove --force ~/baja-check
```

If something went wrong, the backup of step a restores the whole table as it
was:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" baja_resultados < ~/resultado-before-bajaapp62.sql
```

### 3. By hand, in the admin

Everything in the report without a `fix:` line: in the judge area,
*Administrar resultados*, open the resultado, correct its JSON, *Atualizar*
(the page keeps the backup). Prova formulas are left for whoever owns that
prova: saving one recomputes its stored points (BAJAAPP-56).

## Code and tests

The code is under `baja-php/src/Baja/ResultsCheck/`. To hear about formula
errors without changing what the pages do, `Input::solveFormula()` has an
optional listener (`Input::setFormulaErrorListener`), which only this tool
sets (`FormulaErrors`).

```
docker compose exec baja-app vendor/bin/phpunit tests/Unit/ResultsCheck
docker compose exec baja-app vendor/bin/phpunit --testsuite results-check-db
```

The first needs no database and is part of the `unit` suite. The second runs
the tool against the dev seed: it writes nothing and finds the seed's known
cases; it is skipped where there is no database.
