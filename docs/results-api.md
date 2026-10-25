# Public results API

Read-only JSON with the competition's public data: events, teams, results
tables, the live endurance race and tournament brackets. No login, no key,
any origin. Its first consumer is BajaOvrLay, the stream overlay system;
this page is the contract its scoring adapter (BAJAOVRLAY-18) is written
against, and the same endpoints will later serve the general public.

For whoever maintains it: the code is `baja-php/api/resultados/` (one thin
file per endpoint) and `baja-php/src/Baja/Api/Resultados/`. The tests are
listed at the end.

- [Basics](#basics)
- [Endpoints](#endpoints): [evento](#get-resultadoseventophp) ·
  [equipes](#get-resultadosequipesphp) · [resultado](#get-resultadosresultadophp) ·
  [corrida](#get-resultadoscorridaphp) · [torneio](#get-resultadostorneiophp)
- [Spoilers](#spoilers)
- [Caching](#caching)
- [Rate limits](#rate-limits)
- [Cloudflare cache rule (after deploy)](#cloudflare-cache-rule-after-deploy)
- [Stability](#stability)
- [Developing and testing](#developing-and-testing)

## Basics

**Host.** The API vhost: `https://api.<domain>` in production,
`http://api.baja.local` in dev. Every path below is relative to it, e.g.
`https://api.<domain>/resultados/evento.php?evt=26BR`. (The generated docs
at `/` list them as `/api/resultados/…`, the foundation's convention for
`base_url`; on the API host there is no `/api` prefix.)

**Methods.** `GET` (and `HEAD`). Anything else is `405`. A CORS preflight
(`OPTIONS`) is answered, so a browser may send `X-Request-ID`.

**CORS.** `Access-Control-Allow-Origin: *` on every answer, no credentials.
Overlays are pages on hosts we do not know.

**Format.** JSON, UTF-8. Keys are English. Ids are the site's own
(`26BR`, `26BR_GER`, `END`). Times are integers in **milliseconds since
1970, UTC** (`*_ms`); durations are milliseconds too. Names (teams,
schools, resultados, provas) are as stored, so they may carry a trailing
space or an HTML entity the organisers typed; tables' `display` values are
HTML on purpose.

**Ids.** Written exactly as stored, case included: `26br` is a `404`,
not 26BR. One URL per answer keeps the cache effective.

**Query string, strictly.** Each endpoint accepts exactly the parameters
listed for it, and refuses anything else with `400`:

- a parameter it does not document (`?evt=26BR&t=123`): `unknown_parameter`;
- a parameter given twice, an empty one (`&&`, a trailing `&`), or a value
  that does not match its pattern: `invalid_parameter`. Values are checked
  as sent: ids never need percent-encoding, so `26%42R` is refused;
- a required parameter missing: `missing_parameter`.

The order of the parameters does not matter. Anything after the script
name (`/resultados/evento.php/x`) is a `404`.

| Parameter | Pattern | Example |
|---|---|---|
| `evt` | 4 letters or digits | `26BR` |
| `id` | `evt`, `_`, 1 to 3 letters or digits | `26BR_GER` |
| `p` | 1 to 3 letters or digits | `END` |
| `display` | exactly `1` | `1` |

**Errors.** The API foundation's shape, plus a code to test:

```json
{"success":false,"error":"Parâmetro desconhecido \"nocache\". Aceitos: evt, id, display.","code":"unknown_parameter"}
```

| Status | `code` | When |
|---|---|---|
| 400 | `unknown_parameter`, `invalid_parameter`, `missing_parameter` | see above |
| 404 | `not_found` | the event, resultado or prova does not exist (in that event) |
| 404 | `wrong_type` | the id exists but belongs to another endpoint: a tournament asked of `resultado.php`, a table of `torneio.php`, a prova without lap counting of `corrida.php` |
| 405 | (none) | a method other than GET/HEAD |
| 429 | (none) | rate limit; see [Rate limits](#rate-limits) |
| 500 | `internal_error` | a bug; the details are in the server log, not in the answer |

`error` is Portuguese, for people; test `code`. Errors are never cached
(see [Caching](#caching)).

## Endpoints

| Endpoint | Example (dev seed) |
|---|---|
| Event: status, resultados, provas | `/resultados/evento.php?evt=26BR` |
| Teams | `/resultados/equipes.php?evt=26BR` |
| A results table | `/resultados/resultado.php?evt=26BR&id=26BR_GER` (`&display=1` for the HTML) |
| A live race | `/resultados/corrida.php?evt=26BR&p=END` |
| A tournament bracket | `/resultados/torneio.php?evt=26BR&id=26BR_SPR` |

The examples below are real answers from the dev seed, with long lists cut
(marked).

### GET /resultados/evento.php

`?evt=` (required). The event, its status, and what can be fetched.

```json
{
  "event": {
    "id": "26BR",
    "name": "31ª Competição Baja SAE BRASIL",
    "title": "Baja SAE BRASIL - 2026",
    "year": 2026,
    "type": "Nacional",
    "location": "em São José dos Campos - SP",
    "dates": "25 a 29 de Março de 2026",
    "listed": true
  },
  "status": {"default_event": true, "finished": true, "spoilers_active": false},
  "resultados": [
    {"id": "26BR_SGE", "name": "Segurança Estática", "group": "Segurança", "type": "table",
     "provas": ["SGE"], "url": "/resultados/resultado.php?evt=26BR&id=26BR_SGE"},
    {"id": "26BR_END", "name": "Enduro", "group": "Enduro", "type": "table",
     "provas": ["END"], "url": "/resultados/resultado.php?evt=26BR&id=26BR_END"},
    {"id": "26BR_GER", "name": "Geral", "group": null, "type": "table",
     "provas": ["AVF", "MAN", "TRA", "… 23 in all"], "url": "/resultados/resultado.php?evt=26BR&id=26BR_GER"}
  ],
  "provas": [
    {"id": "SGE", "name": "Segurança Estática", "type": "normal", "status": "final",
     "tournament": false, "lap_counted": false, "live_url": null},
    {"id": "END", "name": "Enduro", "type": "laps", "status": "final",
     "tournament": false, "lap_counted": true, "live_url": "/resultados/corrida.php?evt=26BR&p=END"},
    {"id": "EPN", "name": "Penalizações Enduro", "type": "rolling", "status": "final",
     "tournament": false, "lap_counted": false, "live_url": null}
  ],
  "teams_url": "/resultados/equipes.php?evt=26BR"
}
```

| Field | Meaning |
|---|---|
| `event.type` | `Nacional`, `Sudeste`, `Nordeste` or `Sul` |
| `event.listed` | shown in the results site's archive menu. An event that is not is still public at its own address, here as on the site |
| `status.default_event` | the event the results site opens when no event is named in its address; one event at a time has it. It says **nothing about whether the event is running**: a finished event can be the default (26BR above), a running one may not be. Use `finished` for that |
| `status.finished` | the event is over and its results are official. **The only "live or finished" flag**: everything else that depends on it (spoilers, cache times) follows it. An event without it counts as running |
| `status.spoilers_active` | spoilers hide some values right now; see [Spoilers](#spoilers) |
| `resultados` | the event's menu, in menu order. `group` is the submenu it is under (null at the top level). `type`: `table` (fetch `url`, which is `resultado.php`) or `tournament` (`url` is `torneio.php`). `provas`: the provas it is made of |
| `provas` | each prova of those resultados, once. `type`: `normal`, `rolling`, `tournament` or `laps`. `status`: `partial` while judges are still entering it, `final` once closed. `lap_counted` provas have a live race at `live_url` |

Only the menu's resultados are listed, as on the public site. A resultado
the organisers left out of the menu still answers at its own URL, as the
site's page does: 26BR's tournament, `26BR_SPR`, is such a case.

### GET /resultados/equipes.php

`?evt=` (required). The event's teams, by car number. Teams only: nothing
about the people in them.

```json
{
  "event_id": "26BR",
  "teams": [
    {"number": 1, "name": "BAJA UFMG", "short_name": "Baja UFMG",
     "school": "Universidade Federal de Minas Gerais - UFMG", "school_short": "UFMG",
     "city": "Belo Horizonte", "state": "MG", "disqualified": false},
    {"number": 2, "name": "Equipe FEI Baja", "short_name": "FEI",
     "school": "Fundação Educacional Inaciana - FEI", "school_short": "FEI",
     "city": "São Bernardo do Campo", "state": "SP", "disqualified": false}
  ]
}
```

(53 teams in all.) The other endpoints identify a team by `number`; this
is where to look up its names.

### GET /resultados/resultado.php

`?evt=` and `?id=` (required), `?display=1` (optional). One results table,
the overall standings (`…_GER`) or a prova's, **exactly as the public
results page computes it**: the same `Baja\Results\ResultsBuilder`, as the
public sees it. The API adds no rule of its own; the rules are
[`results-rules.md`](results-rules.md).

```json
{
  "event_id": "26BR",
  "resultado_id": "26BR_GER",
  "name": "Geral",
  "provas": ["AVF", "MAN", "TRA", "… 23 in all"],
  "spoilers_active": false,
  "ranking": {"by": "Total", "order": "desc"},
  "columns": [
    {"header": "Pos", "kind": "position", "ranking": false},
    {"header": "#", "kind": "team_number", "ranking": false},
    {"header": "Equipe", "kind": "team", "ranking": false},
    {"header": "Região", "kind": "formula", "ranking": false},
    {"header": "Penalidade", "kind": "formula", "ranking": false},
    {"header": "Segurança", "kind": "formula", "ranking": false},
    {"header": "Projeto", "kind": "formula", "ranking": false},
    {"header": "Dinâmicas", "kind": "formula", "ranking": false},
    {"header": "Enduro", "kind": "formula", "ranking": false},
    {"header": "Total", "kind": "formula", "ranking": true}
  ],
  "rows": [
    {
      "position": 1,
      "team": {"number": 5, "name": "Poli Baja",
               "school": "Escola Politécnica da Universidade de São Paulo - Poli USP",
               "state": "SP", "disqualified": false},
      "cells": [
        {"raw": 1, "display": 1}, {"raw": 5, "display": 5},
        {"raw": "Poli Baja", "display": "Poli Baja"}, {"raw": "SP", "display": "SP"},
        {"raw": 0, "display": 0}, {"raw": -5, "display": -5},
        {"raw": 252.39999999999998, "display": 252.39999999999998},
        {"raw": 238.41, "display": 238.41}, {"raw": 330.43, "display": 330.43},
        {"raw": 816.24, "display": "<b>816.24</b>"}
      ]
    },
    "… 51 rows in all"
  ]
}
```

That is with `&display=1`; without it each cell is only `{"raw": …}`.

| Field | Meaning |
|---|---|
| `spoilers_active` | while true, columns with a spoiler value show it (`"SPOILERS"`, `"?"`) instead of the real one, and positions follow what is shown. See [Spoilers](#spoilers) |
| `ranking` | what the table is ranked by (`by`, a column header or a formula variable) and `order`: `desc` highest first, `asc` lowest first (times). Null when the table ranks nobody |
| `columns` | in the page's order. `kind`: `position` (the Pos column), `team_number`, `team`, `formula`, or `list` (several lines per cell). `ranking`: the column `ranking.by` names. A column with no title has `header: null` |
| `rows` | **in position order**: ranked rows best first, then the rows with no position (by car number). A prova with several runs gives a team several rows. (The page lists rows in another order and lets the visitor sort.) |
| `rows[].position` | 1 for the best, equal numbers for a tie (1, 2, 2, 4). **Null** when the team has no value to rank by, the table ranks nobody, or spoilers hide it (rule R3). Never 0 |
| `rows[].team` | as the table's formulas see it; `number` is the key into `equipes.php` |
| `rows[].cells` | one per column, same order as `columns`. Two columns with the same header share one value, as on the page |
| `cells[].raw` | for programs: HTML and struck-through text removed; a **number** when the text is exactly one (`"12,5"` → 12.5), else the text; `null` when empty; for a `list` column, a list. Some columns define their own (`val_api`). Rules: [results-rules.md, Raw values](results-rules.md#raw-values-for-the-api) |
| `cells[].display` | only with `display=1`: what the page shows, HTML included. A number is sent as the number, at full precision (the page prints `252.4` for the `252.39999999999998` above) |

A tournament resultado is a `404 wrong_type` here; use `torneio.php`.

### GET /resultados/corrida.php

`?evt=` and `?p=` (required): a prova of type `laps`. The endurance race as
the lap counting has it now: standings, laps, last pass, lap times, each
car's status and stops, and the race clock.

Everything comes from the lap counting's public feed
(`Baja\Contagem\Service\PublicFeed`, also served at
`resultados/contagem_feed.php`). The feed **never recalculates the race**:
it reads the snapshot of the last recalculation, which the counting makes
every few seconds at most, and works out the clock-dependent parts on the
spot. This endpoint renames the feed's fields into this contract and adds
nothing of its own. In particular **there are no gaps** between cars, as
the feed has none: work them out from `laps` and `last_pass_ms` if you
need them.

A race ten minutes in (the lap counting's test event `00NE`, three cars;
the seed has no counted race):

```json
{
  "event_id": "00NE",
  "prova_id": "ZZL",
  "name": "Enduro de teste",
  "simulation": false,
  "calculated_at_ms": 1760000600000,
  "clock": {
    "state": "running",
    "start_ms": 1760000000000,
    "finish_ms": null,
    "elapsed_ms": 600000,
    "server_time_ms": 1760000600000,
    "planned_duration_ms": null,
    "scoring_start_ms": 1760000000000,
    "prova_status": "partial"
  },
  "pace": {"median_lap_ms": null, "laps_in_window": 5, "window_s": 900},
  "cars": [
    {"position": 1, "number": 11, "team": "Equipe 11", "school": "Escola 11", "laps": 3,
     "last_pass_ms": 1760000390000, "last_lap_ms": 120000, "best_lap_ms": 120000,
     "expected_lap_ms": null, "status": "running", "status_since_ms": null,
     "pit": {"inside": false, "since_ms": null, "stops": 0, "total_ms": 0, "last_ms": null},
     "fueling": null},
    {"position": 2, "number": 22, "team": "Equipe 22", "school": "Escola 22", "laps": 2,
     "last_pass_ms": 1760000300000, "last_lap_ms": 130000, "best_lap_ms": 130000,
     "expected_lap_ms": null, "status": "running", "status_since_ms": null,
     "pit": {"inside": false, "since_ms": null, "stops": 0, "total_ms": 0, "last_ms": null},
     "fueling": null},
    {"position": null, "number": 33, "team": "Equipe 33", "school": "Escola 33", "laps": 0,
     "last_pass_ms": 1760000050000, "last_lap_ms": null, "best_lap_ms": null,
     "expected_lap_ms": null, "status": "running", "status_since_ms": null,
     "pit": {"inside": false, "since_ms": null, "stops": 0, "total_ms": 0, "last_ms": null},
     "fueling": null}
  ],
  "best_lap": {"number": 11, "team": "Equipe 11", "school": "Escola 11", "lap": 1,
               "lap_time_ms": 120000, "at_ms": 1760000150000},
  "best_lap_history": [
    {"number": 11, "team": "Equipe 11", "school": "Escola 11", "lap": 1,
     "lap_time_ms": 120000, "at_ms": 1760000150000}
  ],
  "recent_stops": []
}
```

The seed's own 26BR Enduro, a race that was run from the spreadsheet and
never counted:

```json
{
  "event_id": "26BR", "prova_id": "END", "name": "Enduro", "simulation": false,
  "calculated_at_ms": null,
  "clock": {"state": "waiting", "start_ms": null, "finish_ms": null, "elapsed_ms": null,
            "server_time_ms": 1792890969037, "planned_duration_ms": null,
            "scoring_start_ms": 1774789261000, "prova_status": "final"},
  "pace": null, "cars": [], "best_lap": null, "best_lap_history": [], "recent_stops": []
}
```

| Field | Meaning |
|---|---|
| `simulation` | the prova accepts simulated passes (a test). Do not show it as a real race |
| `calculated_at_ms` | when the laps were last recalculated. If it stops moving while cars run, the count has stalled; a quiet race keeps it moving. Null before the first calculation |
| `clock.state` | `waiting` (no start yet), `running`, `red_flag`, `finished` |
| `clock.start_ms`, `finish_ms` | the start and the finish race control recorded; null until then |
| `clock.elapsed_ms` | race time **at `server_time_ms`**: since the start, red flags included, frozen at the finish; null before the start. To run a clock, add the time passed since `server_time_ms` (mind your own clock's offset; the answer may be a few seconds old, see [Caching](#caching)) |
| `clock.planned_duration_ms` | how long the race is meant to last. **Always null today**: no prova records it yet. Will be filled in, without a new field, once one does |
| `clock.scoring_start_ms` | the start the results tables use (`prova.tempo`, what `overlaydata.php?time` gives, in ms). Written by the lap counting when it publishes, by the spreadsheet when it does not, so it can be set while `start_ms` is null (as above) |
| `clock.prova_status` | `partial` / `final`, as in `evento.php` |
| `pace` | the field's median lap over the last `window_s` seconds; `median_lap_ms` is null under eight laps |
| `cars` | in standings order. `position` is null for a car with no lap yet |
| `cars[].last_pass_ms`, `last_lap_ms`, `best_lap_ms` | the last official pass, the duration of the last lap, the best lap that counts for best lap |
| `cars[].expected_lap_ms` | what the pace model expects of the car; null while the pace is unknown or the race is not running |
| `cars[].status` | `running`, `late`, `stopped`, `in_pit`, `fueling`, `out`; `status_since_ms` for the four that have a start |
| `cars[].pit`, `fueling` | `inside` now and `since_ms`; `stops` completed, `total_ms` inside (a stay under way included), `last_ms` the last completed stay. `fueling` is null when the prova does not log the fueling station |
| `best_lap`, `best_lap_history` | the race's best lap, and the last ten times it was beaten, oldest first |
| `recent_stops` | the last ten entries and exits of the pit lane and fueling station, newest first: `area` (`pit`/`fueling`), `event` (`enter`/`exit`), `number`, `at_ms`; `inferred`, an exit a reviewer marked; `assumed`, an exit nobody logged that the count assumed |

The field-pace history (`contagem_feed.php?…&ritmo=historico`) is not part
of this API yet.

### GET /resultados/torneio.php

`?evt=` and `?id=` (required): a resultado of type tournament. The bracket,
read the way the public bracket page reads it.

```json
{
  "event_id": "26BR",
  "resultado_id": "26BR_SPR",
  "name": "Chaveamento Super Prime",
  "provas": ["SPR"],
  "rounds": [
    {
      "round": 5,
      "name": "32 avos de Final",
      "matches": [
        {"match_id": 65, "prova_id": "SPR", "best_of": null,
         "slots": [
           {"type": "team", "team": {"number": 6, "short_name": "Imperador", "school_short": "UTFPR"},
            "seed": 1, "wins": null},
           {"type": "bye"}
         ],
         "winner": 6},
        {"match_id": 66, "prova_id": "SPR", "best_of": null,
         "slots": [
           {"type": "team", "team": {"number": 34, "short_name": "ITA", "school_short": "ITA"},
            "seed": 32, "wins": null},
           {"type": "team", "team": {"number": 16, "short_name": "Forja", "school_short": "UFF-VR"},
            "seed": 33, "wins": null}
         ],
         "winner": 16},
        "… 32 matches in all"
      ]
    },
    "… rounds 4 to 1",
    {
      "round": 0,
      "name": "Final",
      "matches": [
        {"match_id": 127, "prova_id": "SPR", "best_of": 3,
         "slots": [
           {"type": "team", "team": {"number": 7, "short_name": "Sinuelo", "school_short": "FAHOR"},
            "seed": 5, "wins": 0},
           {"type": "team", "team": {"number": 5, "short_name": "Poli", "school_short": "Poli"},
            "seed": 2, "wins": 2}
         ],
         "winner": 5}
      ]
    },
    "… round -1, the match for third place"
  ]
}
```

| Field | Meaning |
|---|---|
| `rounds` | first round first, as the page draws them. `round`: 6 for the round of 128, 5 for 64 … 2 quarterfinals, 1 semifinals, 0 final, -1 the match for third place. `name` is the page's (Portuguese) |
| `slots` | the two sides of a match. `type`: `team`; `bye` (nobody: the other team goes through without racing); `pending` (not decided yet: `from_match` and `takes`, `winner` or `loser` of that match); `unknown` (anything the table holds that is none of these) |
| `slots[].seed` | the team's place in the classification the bracket was made from (1 is the top seed; seeds 1 and 2 can only meet in the final). Null when the prova kept no such list |
| `best_of` | 3 for a "best of 3" match, null for a single race. `slots[].wins` then counts the races each side has won |
| `winner` | the winner's car number once decided, else null |

Brackets are public as soon as the organisers publish them, during the
event too: the page has never hidden them during spoilers, and neither
does the API.

## Spoilers

During an event the organisers can turn spoilers on: the columns that have
a spoiler value (`val_spoilers`) show it instead of their real value, so
the final scores stay a surprise until the awards. Rule R11 of
[`results-rules.md`](results-rules.md) says who sees past them; for the
public page **and this API, nobody does**. There is no key, header or
parameter that changes it: every answer is the public one, because every
answer is cached and served to everybody.

What a consumer sees:

- `evento.php`: `status.spoilers_active` is true while spoilers apply
  (they are on and the event is not finished).
- `resultado.php`: `spoilers_active` is true, hidden cells hold the spoiler
  value in `raw` and `display` (often `"SPOILERS"` or `"?"`, which are text,
  not numbers), and positions follow what is shown, which usually means
  `null`. 25SU's overall table during the event:

  ```json
  {
    "resultado_id": "25SU_GER",
    "spoilers_active": true,
    "ranking": {"by": "Total", "order": "desc"},
    "rows": [
      {"position": null,
       "team": {"number": 1, "name": "Procobaja ", "…": "…"},
       "cells": [
         {"raw": null, "display": "–"}, {"raw": 1, "display": 1},
         {"raw": "Procobaja ", "display": "Procobaja "}, {"raw": -15, "display": -15},
         {"raw": "SPOILERS", "display": "SPOILERS"}, {"raw": "SPOILERS", "display": "SPOILERS"},
         {"raw": 84.71, "display": 84.71}, {"raw": 0, "display": 0},
         {"raw": null, "display": null}, {"raw": "SPOILERS", "display": "SPOILERS"}
       ]},
      "…"
    ]
  }
  ```

  (25SU is over; this is its table shown as running, flags changed in
  memory, as the snapshot tool and the tests do.)

Exactly what is hidden is decided by the results builder alone
(`ViewerContext::publicView()`). Today that includes rule R12 of
[`results-rules.md`](results-rules.md): while spoilers apply, a table with
spoiler columns ranks nobody, so every `position` is null. The API follows
whatever the builder decides; a consumer should rely on
`spoilers_active` and on `position` being null, not on which columns show
what.

The live race and the brackets have no spoilers.

## Caching

Every successful answer is `Cache-Control: public, max-age=N, s-maxage=N`:
a browser and Cloudflare may reuse it for N seconds. With N = 5 this server
sees one request per URL every five seconds, however many people are
watching. The numbers are in `Baja\Api\Resultados\CachePolicy`:

| Answer | N |
|---|---|
| `corrida.php` while the event is not finished | 2 |
| everything else while the event is not finished | 5 |
| everything once the event is finished | 300 |

"Finished" is `status.finished` of `evento.php` (the event's `finalizado`
flag) and nothing else; `default_event` plays no part.

So polling faster than N gains nothing. The live race can be up to about
4 s old: the feed keeps its own answer 2 s in Redis, then the edge 2 s;
the clock fields carry `server_time_ms` for that reason.

Errors (`400`, `404`, `405`, `500`) are `Cache-Control: no-store`.
Caching one could serve it to people whose request was fine: the cache key
below leaves out unknown parameters, so `?…&junk=1` and the plain URL
share a key. A `429` comes from the rate limiter before the endpoint runs
and has no `Cache-Control` at all, which the cache rule below also treats
as "do not cache".

An answer depends only on its path and documented parameters: no session
(nginx serves these with `SKIP_AUTH`, so no cookie is read or set), no
`Vary: Origin` (the CORS header is the same `*` for everybody).

## Rate limits

Per client and per endpoint, **120 requests a minute** (sliding window;
`Baja\Api\RateLimiter::LIMIT_RESULTADOS`, next to the API's other limits):
polling each endpoint once a second, all five at once, fits with room for
retries. Past it, `429`:

```json
{"error":"Too Many Requests","message":"Limite de requisições excedido. Tente novamente em 12 segundos.","retry_after":12}
```

with `Retry-After` in seconds. Answers carry `X-RateLimit-Limit`,
`-Remaining` and `-Reset`; on an answer served from Cloudflare's cache they
describe whoever caused the cache miss, so ignore them unless you get a
`429`.

Only requests that reach the server count, and with the cache rule below
most do not: the limit is a backstop for someone defeating the cache, not
the defence. The client is its IP (`RateLimiter::clientIdentifier()`;
behind Cloudflare nginx restores the real one), so a venue putting many
phones behind one address shares one allowance. There are no API keys yet;
when there are, a key will replace the IP in that one function.

## Cloudflare cache rule (after deploy)

`s-maxage` does nothing by itself: Cloudflare does not cache `.php`
answers by default. After the deploy that brings this API, create a Cache
Rule on the zone:

- **When incoming requests match:** Hostname equals `api.<domain>` **and**
  URI Path starts with `/resultados/`.
- **Cache eligibility:** Eligible for cache.
- **Edge TTL:** "Use cache-control header if present, bypass cache if not".
  The origin's `s-maxage` then decides (2, 5 or 300 s), and the errors'
  `no-store` keeps them out.
- **Browser TTL:** respect origin.
- **Cache key → Query string:** include only `evt`, `id`, `p`, `display`.
  Junk parameters then cannot make new cache entries: they do not change
  the key, so such a request is answered from the cached copy of the plain
  URL, or, on a miss, reaches PHP and gets an uncached `400`.

Check it: `curl -sI 'https://api.<domain>/resultados/evento.php?evt=26BR'`
twice; the second must say `cf-cache-status: HIT`. A `BYPASS` or `DYNAMIC`
means the rule does not match. Do the same for `corrida.php` within 2 s.

The existing rule for `resultados.<domain>/contagem_feed.php` (see
[`contagem.md`](contagem.md), deploy notes) is still needed for overlays
that call the feed directly.

## Stability

This is a contract: within `/resultados/`, fields are **added**, never
renamed, removed or retyped. A consumer must ignore fields it does not
know. Lists of words (`state`, `status`, `type`, slot `type`) may gain
values; a word the lap counting adds later is passed on as the counting
spells it, so handle unknown values rather than fail. An incompatible
change would be a new path.

## Developing and testing

- Endpoints: `baja-php/api/resultados/*.php`, each a few lines over
  `Baja\Api\Resultados\Endpoint::serve()` (CORS, strict query string,
  errors, cache headers). The work is in
  `baja-php/src/Baja/Api/Resultados/*Endpoint.php`; each has a `shape()`
  that turns already-loaded data into the JSON body, with no database.
- nginx: `baja-infra/nginx/templates/baja-app-api.conf.template`, the
  `/resultados/` location (`SKIP_AUTH`).
- Generated docs (`GET /` on the API host): regenerate with
  `docker compose exec --user "$(id -u):$(id -g)" baja-app php -r "require 'vendor/autoload.php'; Baja\Api\GenerateDocs::generate();"`.
  Category text in `Baja\Api\ApiDocsConfig`.

Tests, inside the app container except the last one:

| What | Where | Run |
|---|---|---|
| JSON shaping, query string, errors, cache times, rate-limit buckets (no database) | `baja-php/tests/Unit/Api/` | `vendor/bin/phpunit --testsuite unit` (CI) |
| Against the dev seed, read-only: every 26BR table equals the builder's public one, 25SU during spoilers too; the tournament; the lap-counted prova; 404s | `baja-php/tests/Api/Resultados/Db/` | `vendor/bin/phpunit --testsuite results-api-db` (CI, Results snapshots job) |
| A real counted race | `baja-php/tests/Contagem/Db/PublicResultsApiRaceTest.php` | `BAJA_TEST_DB=1 vendor/bin/phpunit --testsuite db` |
| Over HTTP: routing, headers, no cookie, CORS, methods | `baja-php/tests/Api/results-http-test.sh` | on the host: `baja-php/tests/Api/results-http-test.sh http://api.baja.local` |

Response times on the dev stack, for the largest tables (51 to 69 rows, 23
provas): `26BR_GER` 12 ms in PHP, 16 ms median over HTTP through nginx and
the rate limiter (p95 18 ms); `23BR_GER` 10 ms and 17 ms. With
`display=1` the same.
