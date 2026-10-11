# Contagem de voltas (lap counting)

How to run an endurance race with the integrated lap counting, and how it
works inside. Written for whoever sets up and runs the race, and for whoever
maintains the code. Screen labels are quoted in Portuguese, as they appear.

- [Part 1 — Running a race](#part-1--running-a-race)
- [Part 2 — How it works](#part-2--how-it-works)
- [Part 3 — Developing and testing](#part-3--developing-and-testing)

## The idea in one paragraph

Three people each type the number of every car that crosses the counting
line, independently, on their own phone or laptop. Nothing they type is ever
edited or deleted. The server compares the three streams, counts a lap when
at least two of them agree, and shows a reviewer the places where they do
not, each with a suggested fix. Phones keep working with no connection and
upload when they can.

## Vocabulary

| Term | Meaning |
|---|---|
| Fonte (source) | One independent stream of count data: a human counter ("Contagem 1"), an automated system, or imported paper totals |
| Dispositivo (device) | A browser bound to a source, holding an upload token |
| Passagem (entry) | One raw record: "source S says car N crossed the line at time T" |
| Grupo (pass group) | Entries from different sources that describe the same crossing |
| Volta (lap) | A pass group that counts. Lap N of a car is its Nth counted pass after the start |
| Decisão (decision) | A correction made by a person, stored as a new record |
| Alerta (flag) | Something the engine wants a reviewer to look at |
| Ocorrência | A race-control or pit event: start, red flag, restart, finish, pit in, pit out |

---

# Part 1 — Running a race

## Roles

| Role | Permission | What they do |
|---|---|---|
| Controller (responsável pela prova) | `{evento}_{prova}`, for example `26BR_END` | Sets everything up, starts and stops the race, reviews flags |
| Counter (contador) | `{evento}_{prova}_CONT`, granted automatically | Types car numbers on one source |
| Pit log | same as counter | Logs cars entering and leaving the pit lane |

A controller can do everything a counter can. Nobody needs the `admin`
permission on race day.

## Before the event

1. **Make the prova a laps prova.** In `prova.params`, set `"type": "laps"`
   and make sure the inputs include `VOLTAS`, `LAST` and `BEST` (plus
   `DESCLAS` if the scoring formula uses it). The scoring formulas do not
   change. Existing results are not touched until the race is started.
2. **Give the controller the prova permission** (`{evento}_{prova}`) in
   Administração → Usuários, as for any other prova.
3. **Create the sources.** On the prova's hub (`juiz` → click the prova) open
   **Fontes e configurações** and create one source per counter, usually
   "Contagem 1", "Contagem 2", "Contagem 3", type `manual`.
4. **Link the counters.** Open **Contadores**, and under each source type
   the forum username of the person who will count there. That is all: the
   person gets access automatically, and does not need to exist in the
   judges' user list beforehand. Link reliefs to the source they will take
   over. One person can be linked to several sources.
5. **Check the settings** on the same Fontes page. The defaults are meant
   for a three-counter national race; see [Settings](#settings).
6. **Rehearse.** Have each counter log in on the device they will use, open
   the prova and type a few numbers. Then use **Controle da corrida →
   Zerar corrida** to wipe the rehearsal.

## Race day

### Counters

- Log in at `juiz`, click the prova. You land directly on your count page.
  Do this **while you have a connection**: the first load registers your
  device. After that the page works offline and survives a reload.
- Choose how to type (remembered on that device): system keyboard, large
  on-screen keypad, or one tile per car.
- **Type the number as the car approaches; press Enter as it crosses the
  line.** The moment of Enter (or of the tile tap) is the pass time.
- Wrong number? Press **desfazer** next to it in the recent list. Do not try
  to compensate by skipping the next lap.
- The top of the page always shows: your source, the race clock, whether you
  are connected, how many entries are waiting to upload, and who else is
  counting on the same source.
- You see warnings about your own typing only (unknown number, same car
  twice). You never see what the other counters typed. That is deliberate:
  independence is what makes the cross-check worth anything.

### Handover between counters

The person arriving opens the same source on their own device and starts
counting **before** the person leaving stops. For a lap or two both count.
The page shows each of them the other's name. The server understands this
and counts each pass once; nothing reaches the review queue.

### Controller

- **Controle da corrida**: press **Largar agora** at the start. If the
  button was pressed early or late, enter the real start time in the field
  next to it; the latest value wins and results follow immediately.
  Red flag, restart and finish are on the same page.
- **Revisão**: the review screen. Flags are listed with the ones affecting
  the top of the standings first. Select one to see a one-minute timeline
  with a lane per counter, the suggestion and its reason. Shortcuts:
  **A** accept the suggestion, **K** keep both (it is fine as it is),
  **V** void the entry. Every decision is listed in the history panel and
  can be undone.
- The car table at the bottom shows, per car, the official laps next to
  each counter's own count; mismatches are highlighted. Click a car to see
  its whole race and to add a pass nobody recorded.
- Manual fields such as `DESCLAS` are still edited in the usual dashboard
  (link on the hub). Editing them no longer wipes the lap count.

### What the flags mean

| Flag | Meaning | Suggestion |
|---|---|---|
| `troca_provavel` | One counter typed car X alone while the others recorded car Y, which that counter does not have | Reassign the entry to Y |
| `solitaria` | Only one counter recorded this pass | Void it if it would create an impossibly short lap; otherwise the reviewer decides |
| `repetida` | Same counter, same car, twice within 20 s | Double taps and handover duplicates are voided automatically; others suggest voiding the later entry |
| `carro_desconhecido` | The number is not a team present at the event | None |
| `no_box` | A pass while the pit log says the car is in the pit lane | None |
| `volta_curta` | A counted lap less than half the car's expected lap | None |
| `divergencia_total` | Imported paper totals differ from the official count | None |

A pass recorded by two counters and missed by the third is **not** a flag.
It counts, and the miss shows in that counter's statistics.

### Car status

Shown on the review screen and in the overlay feed: `correndo`, `atrasado`
(more than 1.5 expected laps since its last pass), `parado` (more than 2),
`no_box`, `fora`. "Expected lap" follows the pace of the field in the last
15 minutes and the car's own speed. The clock behind it stops during a red
flag. A long gap is never treated as proof of a missed pass.

## When things go wrong

**A counter's phone loses its connection.** Nothing to do. It keeps
counting; the page shows the number of entries waiting. On the review screen
that source's chip turns to "offline". The engine holds back any flag that
depends on what that counter might have recorded, and does not count passes
that need that counter's confirmation, until the phone uploads.

**A counter's device dies (battery, dropped, lost).**

1. Put the counter on another device: they log in, click the prova, and
   keep counting on the same source. A source can have any number of
   devices.
2. If the dead device comes back, open the count page on it with a
   connection: everything it had recorded uploads. Entries are identified
   individually, so nothing is counted twice.
3. If it will not come back, its unsent entries are lost and that counter
   simply has a gap. The other two cover it. If the gap is long, use
   "excluir esta fonte de … até …" on the review screen for that period, so
   the engine applies two-counter rules there instead of charging misses.
4. If the device works but cannot connect at all, use **Exportar meus
   dados** on it to download its full log as CSV, as evidence.

**A counter was confused for a while** (wrong line, wrong cars). Exclude
that source for the interval on the review screen. It can be undone.

**A counter must be removed.** On **Contadores**, press "remover". Their
device stops uploading at once; the page tells them so, keeps their data and
offers the export.

**The server or the internet is down for everybody.** Counters keep
counting offline. Results freeze. When the connection returns, everything
uploads and the standings catch up within seconds.

**The count looks wrong after a correction.** On Controle da corrida,
**Reconstruir** recomputes all laps from the raw entries, and **Republicar
tudo** rewrites every car's result. Both are safe at any time.

**A team protests.** Click the car on the review screen: every entry of
every counter for that car is on the timeline, including voided ones, with
who decided what and when. The CSV exports on Controle da corrida contain
the same.

## Settings

Stored in `prova.params.contagem`, edited on **Fontes e configurações**,
effective immediately.

| Key | Default | Meaning |
|---|---|---|
| `modo_publicacao` | `confirmadas` | Which passes count: `confirmadas` (two sources agree, or a reviewer said so), `fonte_principal` (whatever the primary source has), `todas` (everything not voided) |
| `fonte_principal_id` | none | The source trusted by `fonte_principal` |
| `janela_ms` | 20000 | Entries for the same car within this window are the same crossing |
| `duplicata_ms` | 5000 | A repeat from the same source inside this is a double tap, voided automatically |
| `fracao_volta_minima` | 0.5 | Below this fraction of the expected lap, a lap is "too short to be real" |
| `janela_ritmo_s` | 900 | How far back the field pace looks |
| `fator_atrasado` / `fator_parado` | 1.5 / 2.0 | Car status thresholds, in expected laps |
| `dispersao_max_ms` | 2000 | Sources must agree within this for a lap to qualify as best lap |
| `fonte_offline_s` | 60 | Silence after which a source is shown as offline |

## Other sources

- **Automated systems** post to the same endpoint as the browsers. Create
  the source with type `automatica` on the Fontes page, press "criar token"
  and give the token (shown once) to the system. If its timing is
  trustworthy set its timing grade to `precisa`; its times then replace the
  human ones.
- **Paper totals**: create a source of type `totais` and paste
  `carro, voltas` lines. Totals never change the official count; they are
  compared with it and differences are flagged.

## The old spreadsheet

`juiz/remote.php` still accepts the spreadsheet's totals, so the two systems
can run side by side while the new one is being validated. Do not point both
at the same prova in production at the same time: both write `VOLTAS`.

---

# Part 2 — How it works

## Data

All in MySQL, tables `contagem_*` (see `baja-php/schema.xml`). Times are
integer milliseconds since the Unix epoch, UTC.

| Table | Content | Written by |
|---|---|---|
| `contagem_fonte` | Sources | Fontes page |
| `contagem_fonte_usuario` | Which user may count on which source | Contadores page |
| `contagem_dispositivo` | Devices and their token hashes, last contact, "synced through" | Device registration, sync |
| `contagem_passagem` | Raw entries. **Append-only** | Sync |
| `contagem_ocorrencia` | Race control and pit events. Append-only | Controle page, pit log |
| `contagem_decisao` | Corrections. Append-only | Review screen, counters' "desfazer" |
| `contagem_volta` | Derived laps. Disposable | Engine |
| `contagem_total` | Imported totals | Fontes page |

The only code that deletes from the raw tables is "Zerar corrida".

Reviewer presence ("X is looking at this flag") and the state version used
by the review screen's polling live in Redis and may be lost at any time.

## Flow of one upload

```
phone ──POST contagem_sync.php (Bearer token)──▶ SyncHandler
                                                   │ store new entries (idempotent by uuid)
                                                   │ update the device's "synced through" mark
                                                   ▼
                                    GET_LOCK per prova
                                                   │
                           EngineInputLoader ──▶ Engine::run ──▶ EngineResult
                                                   │
                                   LapStore: replace contagem_volta
                                   ScorePublisher: merge VOLTAS/LAST/BEST into Input.dados,
                                                   refreshVarsAndPontos() if anything changed
```

Scores are refreshed in the same request that changed a count, every time.
A lap count is never visible without its points.

## The engine

`baja-php/src/Baja/Contagem/Engine/`. Pure PHP: no database, no clock. It
receives every entry, decision and ocorrência of the prova plus "now", and
recomputes the whole race on every run. There is no incremental state to
drift; the result after a correction is what a fresh start would give. A
synthetic race of 100 cars × 100 laps × 4 sources recomputes in roughly
120 ms on a laptop (budget: 300 ms, enforced by a test).

| Step | Class | What it does |
|---|---|---|
| 1 | `DecisionApplier` | Drops voided entries, moves reassigned ones, drops entries of excluded sources, adds reviewer passes, cancels undone decisions |
| 2 | `DuplicateFilter` | Voids double taps and handover duplicates; flags other repeats |
| 3 | `PassMatcher`, `PassTimeFuser` | Groups entries per car by time (never by typing order); one fused time per group |
| 4 | `PublishRule`, `LapBuilder` | Decides which groups count; numbers and times the laps |
| 5 | `FlagDetector`, `SwapFinder` | Produces the flags and their suggestions |
| 6 | `PaceModel`, `CarStatus` | Expected lap per car; status of each car now |

Supporting classes: `RaceTimeline` (start, red flags, finish, pit stays),
`SourceAvailability` (who was expected to be counting, and whether we know
what they recorded), `Settings`, `PublishedValues`.

### "Missed" versus "offline"

Every device calls the server every 5 seconds. When its upload queue is
empty it says so, and the server records that moment as the device's "synced
through" mark. A source is *known* at time T only once it has synced past
T plus the matching window. Until then its silence proves nothing: flags
that depend on it are held back, a pass waiting for its confirmation stays
pending, and no car is declared late.

### Lap times

The time of an entry is the Enter press. For each source the engine measures
its habitual offset (median of "its time minus the group's time" over its
last 30 matched entries) and removes it; the pass time is the median of the
corrected times, or the time of a `precisa` source if there is one. Lap 1 is
measured from the start and is never a best lap. A lap qualifies for best
lap only if both its ends are agreed by two sources within
`dispersao_max_ms` (or come from a precise source), it has no open flag, it
does not overlap a pit stay or a red flag, and it is not too short. Times
are shown in whole seconds.

## Other parts

| Folder | Content |
|---|---|
| `src/Baja/Contagem/Service/` | Loads data, runs the engine under the per-prova lock, saves laps, publishes scores, builds the public feed |
| `src/Baja/Contagem/Sync/` | Upload endpoint logic, device tokens, clock correction |
| `src/Baja/Contagem/Access/` | Who may do what; counter links and the automatic `_CONT` permission |
| `src/Baja/Contagem/Review/` | State snapshot for the review screen, decision validation and conflict detection, reviewer presence |
| `juiz/contagem*.php` | Thin pages and endpoints |
| `resultados/contagem_feed.php` | Public JSON feed for overlays |
| `default/js/contagem/` | ES modules: local store, sync queue, clock, entry modes, review screen, service worker |

### Upload protocol

`POST juiz/contagem_sync.php` with `Authorization: Bearer <device token>`.
It needs no phpBB session, so an expired login or a changed network does
not interrupt a counter.

```json
{
  "entries":    [{"uuid": "…", "car": 41, "ts_device_ms": 1760000000000,
                  "clock_offset_ms": -120, "typing_ms": 850, "mode": "teclado"}],
  "voids":      [{"uuid": "…"}],
  "pit_events": [{"uuid": "…", "car": 41, "tipo": "box_entrada",
                  "ts_device_ms": 1760000000000, "clock_offset_ms": -120}],
  "device_now_ms": 1760000000500,
  "clock_offset_ms": -120,
  "queue_empty": false
}
```

The response lists the uuids now stored, the server time, the race state and
the valid cars. Sending the same entries again changes nothing. A revoked or
unknown token gets HTTP 401; the page then stops uploading, keeps its data
and offers the export. External systems use the same request with
`"mode": "api"`.

Clocks: the browser estimates its offset from the server on every call
(midpoint of request and response, keeping the sample with the shortest
round trip) and sends raw device time plus that offset. Entries made before
any estimate exists are stored with no corrected time; the device's first
later estimate is applied when they are read.

### Concurrent reviewers

The review screen polls `contagem_estado.php` every 2 seconds. Each flag in
the state carries a signature of the entries it is about. A decision is sent
with that signature; if, under the lock, the signature no longer matches
(someone else decided, or new entries changed the group), the server answers
409 with the fresh state and the screen says "já decidido por …" or "os
dados mudaram". Decisions are never queued offline.

### Public feed

`resultados/contagem_feed.php?evt=26BR&p=END`: race state, per-car laps,
last pass, last lap, best lap and status, the best lap of the race and the
last ten "new best lap" events. Open CORS, cached for 2 seconds. The
existing `overlaydata.php` is unchanged and keeps reading `Input.dados`.

---

# Part 3 — Developing and testing

## Dev stack

A fresh `docker compose up` (see `docs/dev-workflow.md`) seeds event `26BR`
with prova `END` as a laps prova, three sources, and these users (password
`123456`):

| User | Role |
|---|---|
| `juiz1` | Controller |
| `equipe1`, `capitao2` | Counters on Contagem 1 (two people, to try a handover) |
| `equipe2` | Counter on Contagem 2 |
| `capitao1` | Counter on Contagem 3 |

To try it: log in as `juiz1`, open the prova, press **Largar agora**; open
three more browsers (or private windows) as the counters and type numbers.
Put one browser offline in its dev tools for a minute and bring it back.

Production gets the tables from
`baja-infra/mysql/migrations/2026-contagem.sql`.

## Tests

| What | Where | Run |
|---|---|---|
| Engine (no database) | `baja-php/tests/Contagem/Engine/` | `vendor/bin/phpunit --testsuite engine` |
| Sync, publishing, counter links, decision conflicts (dev database) | `baja-php/tests/Contagem/Db/` | `BAJA_TEST_DB=1 vendor/bin/phpunit --testsuite db`, inside the `baja-app` container |
| Browser logic | `baja-php/tests/js/` | `node --test "baja-php/tests/js/*.test.js"` |
| Smoke | `baja-php/tests/smoke-test.sh` | against a running stack |

The engine tests are written to be read as the specification. Each one says
what situation it sets up, what a human counter did wrong, and what the
system is expected to do. `Support/RaceScenario` builds small hand-written
races; `Support/SyntheticRace` generates whole races from a seed, with known
truth and configurable human error. No real race data is in the repository.

## Choices worth knowing about

- **Full recompute, no incremental state.** Simple and always consistent;
  fast enough by a wide margin. If the performance test ever fails, add a
  per-car incremental path and a test proving it equals the full rebuild.
- **A source's sync mark is the most recent mark among its devices.** With
  two devices on one source during a handover, one of them being offline can
  therefore cause a flag to appear a little early; it corrects itself when
  that device uploads.
- **A lone entry waits while other counters are merely offline.** It only
  counts on its own when no other source is active or all others were
  excluded for that moment.
- **Lone entries are flagged as soon as one other source is expected**, not
  two. With only two counters a disagreement would otherwise be neither
  counted nor shown to anybody.
- **The finish does not cut off counting.** Cars completing their lap after
  the finish time still get it; counters stop typing when the flag has been
  shown to each car.
- **Nothing is published before the first start.** Switching an existing
  prova to type `laps` therefore cannot wipe results it already has.
