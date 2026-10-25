# Formula engine

Every prova and resultado config is full of formulas: `ROUND($PONTOS, 2)`,
`$T1 < $T2 ? $T1 : $T2`, `'<b>' . $Total . '</b>'`. The site has always run
them with PHP's `eval()`, so **anyone with an admin account can run any code
on the server** (`system('...')` in a column is enough). BAJAAPP-35 replaces
`eval()` with an engine that reads the formula itself and allows only the
small part of PHP the configs use.

This page is about phases 3 and 4 of BAJAAPP-35: the new engine, built and
proven. **It is not switched on yet.** The site still runs `eval()` until the
comparison below has been run on staging against a copy of production and is
clean.

The code is under `baja-php/src/Baja/Formula/`; the tests under
`baja-php/tests/Unit/Formula/` (read them first: each construct and each
function is compared with `eval()` on the same input).

## The setting

`FORMULA_ENGINE` in `baja-infra/.env` (passed to `baja-app` by
`docker-compose.yml`):

| Value | Engine | What the pages get |
|---|---|---|
| blank or `eval` (default) | `EvalEngine` | `eval()`, as always |
| `safe` | `SafeEngine` | the new engine only |
| `shadow` | `ShadowEngine` | `eval()`'s answer; the new engine runs beside it and every difference is logged |

Anything else is logged as a warning and `eval` is used. A change takes
effect when `baja-app` is recreated (`docker compose up -d baja-app`).

All formulas go through `Input::solveFormula()`, which asks the configured
engine and turns any error into `null` (an empty cell), as before. The config
check's error listener (`Input::setFormulaErrorListener`) hears the errors with
all three: in `shadow` mode it hears `eval()`'s.

In `shadow` mode, each difference is logged once per request as a warning
`Formula engines differ`, with the formula, the variables it reads, and both
engines' value and error; at most 100 per request, then one note. Read them
with `docker compose logs baja-app | grep 'Formula engines differ'`. On the
biggest seed resultado a page takes about 1.7 ms longer (see
[Speed](#speed)).

## How the new engine works

`SafeEngine` reads a formula with `Syntax\Parser` (a `FormulaSyntaxError`,
which is a `ParseError`, for anything it does not accept: nothing of a refused
formula ever runs), turns it into PHP closures (`Interpreter`), and keeps
them for the rest of the request.

It does not reimplement PHP. Each operator is PHP's own operator applied to
the formula's values, and each function is PHP's own function. So PHP 8's
rules come with it, quirks included: loose `==` (`"2 - Até 10 min" == 2` is
false in PHP 8), text in arithmetic (`"5 pts" + 1` is 6, `'<b>' + 1.5` a
TypeError), `round()`/`max()`/`min()` edge cases, `max([])` throwing,
division by zero throwing. A formula that fails under `eval()` fails under
`SafeEngine` too, with the same error and message (but for syntax errors,
whose messages are SafeEngine's own).

Two details make that true:

- `Interpreter.php` (and `PinnedClock.php`) must **not** declare
  `strict_types`. PHP checks a call's arguments by the rules of the file the
  call is written in, and `eval()`'d code never was strict: `round("2.5")` is
  3 there, a TypeError in a strict file. A test guards this.
- `SafeEngine` does what `eval()` did around the formula: the variables are
  `extract()`ed (a variable called `this` makes every formula fail; one called
  `formula` replaces the formula; `$vars` and `$formula` can be read), the
  formula is made text as `"...$formula..."` made it (the number `2.0` is the
  formula `2`), and PHP's warnings are silenced as `@eval()` silenced them.

### What a formula may use

- variables `$NAME` (letters, digits, `_`, accented letters; not starting with
  a digit), `$A[0]`, `$A['x']`;
- numbers, `'text'`, `"text"` (with `\n`… escapes and plain `$NAME` inside),
  `null`/`true`/`false`, `[1, 2]`, `array(1, 2)`, keys with `=>`;
- `+ - * / % **`, `.` (joins text), `== != === !== <> < <= > >=`,
  `&& || !`, `? :`, `?:`, `??`, `isset()`; PHP 8's precedence and
  associativity, so `1 + 2 . 3` is `"33"` and `-2 ** 2` is -4;
- the functions found in production's configs (design survey of 2026-10-18):
  `round`, `max`, `min`, `array_diff`, `date`, `ceil`, `floor`, `count`,
  `array_sum`, `abs`, `substr`, `time`, `str_pad`, `implode`, and
  `array_filter` with one argument; names in any case;
- the constants `STR_PAD_LEFT`/`RIGHT`/`BOTH`, `Pos`, `EquipeNum`, `Equipe`
  (defined in `src/bootstrap.php`), and a few harmless ones (`PHP_EOL`,
  `PHP_INT_MAX`, `M_PI`, `PHP_ROUND_HALF_UP`…; `Allowlist::CONSTANTS`). Any
  other name is "Undefined constant" when it is reached, as in PHP: so
  `SPOILERS` without quotes still gives null;
- the one object pattern of the configs (32 enduro formulas):
  `(new \DateTime(<a>, new \DateTimeZone(<z>)))->setTimestamp(<t>)->format(<f>)`,
  with or without the time zone, the `setTimestamp()` or the backslashes. It
  runs exactly those PHP calls.

The parser also lists each formula's variables and functions
(`SafeEngine::read($formula)->variables`). The config check uses that to
report unknown variables ([`results-config-check.md`](results-config-check.md),
check 4); the future validator and editor will use it too.

## Deliberate differences

Everything else must give exactly what `eval()` gives. These are the
exceptions, on purpose:

1. **Formulas on which `eval()` kills PHP give null instead.** PHP refuses to
   compile some formulas with a fatal error that no code can catch: the whole
   results page dies (or the judge's save). `SafeEngine` refuses them with a
   normal error: one empty cell, and the page goes on. Found so far:
   - two `? :` side by side without brackets, `$A ? 1 : $B ? 2 : 3`, and the
     same with `?:` on one side (18SU's APR, in production). The error is
     PHP's own text: ``Unparenthesized `a ? b : c ? d : e` is not
     supported…``;
   - `isset(<not a variable>)`, `[1, , 2]`, `[[] => 1]` (an array as a key
     written in the formula), `$X[]` read;
   - `exit`/`die` (they stop PHP too: the page ends there).

   The comparison lists these as DELIBERATE, not as differences.
2. **Anything outside the list above is refused** (null), even where `eval()`
   would give a value: other functions (`strtoupper()`, `number_format()`, and
   of course `system()`), other constants (`PHP_VERSION`), objects and `new`
   but the DateTime pattern, `::`, calling a variable or a text as a
   function, `array_filter()` with a callback (it names a function to call),
   superglobals (`$_SERVER`, `$GLOBALS`…), assignments, `++`/`--`, the
   keywords `and`/`or`/`xor`, casts, bitwise operators, `@`, `empty()`,
   `match`, closures, `{$…}` and `$X[…]` inside `"…"`, more than one statement,
   and `?>` (with which `eval()` would print the rest of the formula into the
   page). That is the point of the engine. None of it appears in the design
   survey of production; if the staging run finds a formula that uses one of
   them, it shows as a DIFFERENT VALUE and must be decided: allowed (added to
   `Allowlist`, if harmless) or kept refused (added here, and the config fixed).

Not a difference in results, but in wording: a formula that is not valid PHP
gets SafeEngine's message (`expected "," or ")" but found end of the formula,
at character 12`) instead of PHP's (`syntax error, unexpected token ";"`). The
config check prints whichever engine is configured.

## The comparison: `scripts/formula-engine-compare.php`

For every prova and resultado of the database (or of `--events`), for every
team, it works every formula out with both engines and lists where they
differ, grouped by formula. It runs the site's own code, so each formula gets
the variables it really gets on the site:

- each prova: `Input::updateVars()` and `updatePontos()` for every team, with
  the totals as stored (as the results snapshots do), and, for a tournament
  prova, its seeding formulas as `juiz/torneio.php` runs them;
- each resultado: the results builder, as the public sees a finished event,
  and again with spoilers on when a column has a `val_spoilers`;
- formulas no team runs: once each, with no variables.

`eval()`'s answer is what flows on (into later columns, into the points), as
on the site. Both engines see the same fixed "now" in `time()` and `date()`
(`--now <unix time>`, default the start of the run), so the clock-driven
19SU "Status?" columns compare too. (`new DateTime('now')` without a
`setTimestamp()` still reads the real clock, in both.)

It only reads: every connection is switched to read-only, as in the results
snapshots. Formulas on which `eval()` kills PHP are found first, in throw-away
processes, so that they cannot end the run; each prova and resultado is then
compared in a process of its own, four at a time (`--jobs <n>`).

```
php scripts/formula-engine-compare.php [--events 19SU,26BR] [--jobs 8] [--now 1760000000]
```

The report:

```
Formula engine comparison (eval() and SafeEngine)
  17 events, 358 provas, 387 resultados; fixed now 2026-10-25 01:45:04 (1792903504); 9.1 s
  1,073 distinct formulas in 3,345 places, 79,223 evaluations (formula x team); 3 formulas no team runs were compared once, with no variables

DIFFERENT VALUES (a page would change; each must be fixed or listed as deliberate): 0 formulas

DELIBERATE: eval() kills PHP on these, SafeEngine gives null and the page goes on: 0 formulas

SAME VALUE, OTHER ERROR (no page changes; only the config check's wording would): 0 formulas

Result: no difference but the deliberate ones.
```

That is the dev seed. Each listed formula comes with where it is written, how
many of its evaluations differ, and up to three examples with the variables
it reads and both answers:

```
  formula: "strtoupper($NOME)"
    where:   98FE_OFF prova pontos.OFFPTS
    2 of 2 evaluations
    with {"NOME":"abc"}
      eval: "ABC"
      safe: Baja\Formula\Syntax\FormulaSyntaxError: function strtoupper() is not allowed in a formula: ...
```

Exit code 0: nothing differs but the deliberate ones. 1: something else
differs, or a process died (listed under PROCESSES THAT DIED). 2: it could
not run.

**The bar is zero DIFFERENT VALUES.** For each one: if SafeEngine is wrong,
fix it (and add a test to `tests/Unit/Formula/` comparing with `eval()`); if
it is a deliberate refusal, add it to the list above with the reason. SAME
VALUE, OTHER ERROR changes no page; read it anyway.

## On staging, against the copy of production

Production has 87 events (61 with results configs in the design survey), many
more than the seed, pre-2022 ones included. Run this before the engine is
ever switched on. Paths assume the repository at `~/baja-sae-brasil-online`,
as in `server-migration.md`.

On the staging server, from the repository's `baja-infra/` folder, with a
fresh copy of production loaded:

```
# 0. Once: a read-only MySQL account for the tools (pick your own password).
docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "CREATE USER IF NOT EXISTS snapshot@\"%\" IDENTIFIED BY \"change-me\"; GRANT SELECT ON baja_resultados.* TO snapshot@\"%\";"'

# 1. The code with the engine (this branch, or develop once it is merged).
git fetch origin
git checkout feature/formula-engine
git pull
docker compose exec --user "$(id -u):$(id -g)" baja-app composer install --no-dev --no-interaction

# 2. The comparison, on every event. Exit code 0 = only deliberate differences.
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me \
    baja-app php scripts/formula-engine-compare.php --jobs 4 > ~/formula-engine-compare.txt
echo "exit code: $?"

# 3. Every results page, premiação and prova of every event, built with eval()...
EVENTS=$(docker compose exec -T mysql sh -c 'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" baja_resultados -e "SELECT GROUP_CONCAT(evento_id ORDER BY evento_id) FROM evento"' 2>/dev/null)
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me -e FORMULA_ENGINE=eval \
    baja-app php scripts/results-snapshot.php --update --dir /tmp/formula-eval --events "$EVENTS"

# 4. ...and checked with the new engine. Exit code 0 = not one cell differs.
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me -e FORMULA_ENGINE=safe \
    baja-app php scripts/results-snapshot.php --check --dir /tmp/formula-eval --events "$EVENTS" > ~/formula-engine-snapshots.txt
echo "exit code: $?"

# 5. The unknown variables (and the rest of the config check), for the list of configs to fix.
docker compose exec --user "$(id -u):$(id -g)" -e PHPBB_DB_USER=snapshot -e PHPBB_DB_PASS=change-me \
    baja-app php scripts/check-results-configs.php > ~/results-config-report.txt
```

Things to keep in mind:

- Step 2 takes about a minute (the seed's 745 provas and resultados take 9 s
  with `--jobs 4`). It prints progress every 100.
- Steps 3 and 4 compare the two engines on the same code and the same data,
  so any difference is the engine's. `--events "$EVENTS"` names every event,
  finished or not (without it the snapshots only take finished ones; on the
  seed that is 636 snapshots, with every event named 765). The data must not
  change between them: on a static copy it does not.
- `/tmp/formula-eval` lives inside the `baja-app` container: do not recreate
  the container between steps 3 and 4.
- The reports and `/tmp/formula-eval` hold real team names and scores; treat
  them like the data they come from. They are not for committing.
- The formulas run with `eval()` here exactly as on the production site
  (every visit to a results page runs them), so nothing new runs on staging.

Send `~/formula-engine-compare.txt` and `~/formula-engine-snapshots.txt` back
to the PR or the ticket. Only when both are clean (or every difference is
listed above with its reason) does the next step start: `shadow` on
production for one event, then `safe`.

## Tests

```
docker compose exec baja-app vendor/bin/phpunit tests/Unit/Formula
docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite formula-compare-db
docker compose exec --user "$(id -u):$(id -g)" -e FORMULA_ENGINE=safe baja-app php scripts/results-snapshot.php --check
```

- `tests/Unit/Formula/` (part of the `unit` suite): `GrammarTest` (each piece
  of syntax), `FunctionsTest` (each allowed function, the DateTime pattern,
  the clock), `LegacyFormulasTest` (production's broken formulas still give
  null; what `extract()` did; the fatal ones), `SecurityTest` (54 attacks,
  each refused before anything runs), `ShadowEngineTest`,
  `EngineSettingTest`, `ParserTest`. All but the last three compare with
  `eval()` on the same input.
- `formula-compare-db`: the comparison on the seed finds nothing and writes
  nothing (`SeedComparisonTest`); on a made-up event, 98FE, it finds the two
  differences and the two fatal formulas it was given (`CompareFixtureTest`,
  which writes the event, so needs `BAJA_TEST_DB=1`).
- CI runs both, and `results-snapshot.php --check` a second time with
  `FORMULA_ENGINE=safe`.

## Speed

`scripts/formula-engine-benchmark.php <resultado>` builds one resultado in
fresh processes, per engine: once cold, as a page does (no formula read
yet), then 20 times warm, and checks that every engine built the same table.
The engine's classes are loaded before timing, as php-fpm's opcache has them
ready on the site. Median of 9 runs, in ms, in the dev container:

| Resultado | eval cold | safe cold | shadow cold | eval warm | safe warm |
|---|---:|---:|---:|---:|---:|
| 26BR_GER (51 rows) | 4.1 | 3.6 | 5.8 | 2.9 | 1.6 |
| 24BR_DML (56 rows, the most formula runs) | 2.0 | 1.6 | 2.6 | 1.2 | 0.7 |

Reading a formula takes about 15 µs; `eval()` compiles one in about 2 µs but
compiles it again for every team, where SafeEngine reads it once per page.
