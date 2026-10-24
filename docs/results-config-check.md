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

Each resultado and each prova is checked in a PHP process of its own
(`scripts/check-results-configs/child.php`), four at a time (`--jobs <n>` to
change that), so that a formula that kills PHP ends only its own check. See
[Fatal errors](#fatal-errors) below.

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

Exit code 0 whenever the report was made, whatever it found, fatal errors
included; 2 when it could not run (bad options, no database).

## Fatal errors

Most broken formulas *raise* an error, which `Input::solveFormula()` catches:
the cell is left empty, the page carries on, and the check lists it under
*Formula raises an error*. A **fatal** error is different: PHP stops dead.
No `try`/`catch` can stop it, and nothing after it runs. In production:

- in a **resultado**, that results page dies outright: visitors get an error
  instead of the results;
- in a **prova**, a judge saving an entry for it gets a fatal error, since
  saving runs the prova's formulas.

The usual cause is a formula PHP 8 refuses to compile. That is what stopped
the check itself on a copy of production (BAJAAPP-63), in 18SU's APR prova:

```
($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))
```

So the check runs each resultado and prova in its own process. When one dies,
it gets a finding *FATAL ERROR: PHP dies*, and the check carries on with the
rest. To name the formula, each formula of that resultado or prova is then run
alone, again in a process of its own; those that die alone are the culprits.
If none does (the error needs the teams' data, say), the finding is about
*the resultado as a whole*. A resultado or prova whose check died has no other
findings: its check stopped before they were made.

Fatal errors are listed first, in a banner at the top of the report, and again,
with the formula, under their event:

```
!!! 2 FATAL ERRORS !!!
PHP stops dead on these: no try/catch can catch it, and nothing after it runs.

  18SU_APR  Apresentação  (resultado: its results page dies outright in production)
      column "Pontos" val: Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`
  18SU_APR  Apresentação  (prova: a judge saving an entry for it gets a fatal error)
      pontos.APRPTS: Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`

The formulas, and how to read them, are under each event below.

=== 18SU ===

18SU_APR  Apresentação  (PHP died while checking it, so its other checks did not run)
  FATAL ERROR: PHP dies
    column "Pontos" val
      formula: ($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : $DTA == 3 ?  -45 : -90))
      fatal:   Unparenthesized `a ? b : c ? d : e` is not supported. Use either `(a ? b : c) ? d : e` or `a ? b : (c ? d : e)`
      A "? :" inside another without brackets: PHP 8 refuses to guess which one comes first.
      PHP 7 read it as:  ($DTA == 1 ?  0 : (($DTA == 2 ?  -30 : $DTA == 3) ?  -45 : -90))
      probably meant:    ($DTA == 1 ?  0 : ($DTA == 2 ?  -30 : ($DTA == 3 ?  -45 : -90)))
      no fix:  a person must pick the reading and add the brackets; the two can give different results.
```

### Reading the two readings

`a ? b : c` means "if a then b, else c". Two of them side by side with no
brackets, `a ? b : c ? d : e`, can be read two ways, and the report writes the
formula out both ways, changing nothing but the brackets:

- **PHP 7 read it as** `(a ? b : c) ? d : e`: the first one is worked out
  first, and its answer becomes the condition of the second. Points worked out
  while production ran PHP 7 came from this reading.
- **probably meant** `a ? b : (c ? d : e)`: "if a then b, else if c then d,
  else e", as in almost every other language.

Compare them for a value. Above, with `$DTA = 2`: PHP 7's reading first works
out `$DTA == 2 ?  -30 : …`, which is `-30`; `-30` counts as "true", so it
answers `-45`. The meant reading answers `-30`. With `$DTA = 1, 3, 4` both
give the same.

The tool never picks one and writes no `--sql` for it: picking the meant
reading changes points that were already published, and only someone who
knows the rules of that prova can say which is right. Fix it in the admin, as
in step 3 of the runbook, typing the brackets of the reading you chose. For a
prova, saving recomputes its stored points (BAJAAPP-56), which is the point
here, but check them afterwards.

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
sets (`FormulaErrors`). `IsolatedCheck` starts the child processes, with the
results snapshots' `ChildProcess` and `ProcessPool`, and `NestedTernary`
writes the two readings.

```
docker compose exec baja-app vendor/bin/phpunit tests/Unit/ResultsCheck
docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite results-check-db
```

The first needs no database and is part of the `unit` suite. The second runs
the tool against the dev seed: it writes nothing and finds the seed's known
cases; it is skipped where there is no database. Its `FatalFormulaTest` loads
a made-up event, `99FX` (`tests/ResultsCheck/Db/fatal-formulas.sql`), with
the APR formula in a resultado and in a prova, checks that the run gets to the
end and reports both, and deletes the event again. It writes, so it runs only
with `BAJA_TEST_DB=1`; never set that against production.

### Run time

One process per resultado and prova costs about 50 ms each. On the dev seed
(17 events, 387 resultados, 358 provas): 1.6 s in one process before
BAJAAPP-63, now 8.6 s with the default `--jobs 4`, 4.5 s with `--jobs 8`.
Production has about five times as many events, so expect under a minute.
