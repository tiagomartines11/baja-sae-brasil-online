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
edited or deleted. The server compares the three streams, counts a pass when
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
| Passagem oficial (official pass) | A pass group that counts |
| Volta (lap) | What lies between two official passes of a car. See the next section |
| Decisão (decision) | A correction made by a person, stored as a new record |
| Alerta (flag) | Something the engine wants a reviewer to look at |
| Ocorrência | A race-control or stop event: start, red flag, restart, finish, pit lane in/out, fueling station in/out |
| Horário oficial | The server's time, as each page derives it. Every time in this feature is in it; no device's own clock is trusted |
| Classificado | A car that may race: present at the event and marked "SIM" in the prova's classification field |

## Laps = passes − 1

The counting line is after the start line. A car's first crossing therefore
completes no lap: it is the car's **pass zero** ("passagem zero"). Its second
crossing completes lap 1. So a car that the counters typed 43 times has 42
laps. Every lap is measured from one crossing of the counting line to the
next, so every lap is a full lap.

This is the default (`passagem_inicial = primeira_passagem`). The other
value, `largada`, is for a race where the organisers decide not to type the
first crossing at all: the start itself is everybody's pass zero and every
typed pass is a lap. With it comes `janela_largada_s`: entries in the first N
seconds after the start are treated as that first crossing and never count,
so a counter who types the start pack anyway gives nobody an extra lap.

The crossing right after the start is the hardest moment of the race to
count, and with the default rule a car whose pass zero nobody caught silently
loses a lap. The flag `primeira_passagem_tardia` exists for that: see below.

---

# Part 1 — Running a race

## Roles

| Role | Permission | What they do |
|---|---|---|
| Controller (responsável pela prova) | `{evento}_{prova}`, for example `26BR_END` | Sets everything up, starts and stops the race, reviews flags |
| Counter (contador) | `{evento}_{prova}_CONT`, granted automatically | Types car numbers on one source |
| Pit log | same as counter | Logs cars entering and leaving the pit lane and the fueling station |
| Admin | `admin` | Only needed to wipe a race that has been started |

A controller can do everything a counter can.

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
   the forum username of the person who will count there. The person gets
   access automatically. Link reliefs to the source they will take over.
5. **Check the settings** on the Fontes page; see [Settings](#settings). The
   ones to decide on purpose are the pass-zero rule, the publish mode, the
   classification field and whether the fueling station is logged.
6. **Say which cars may race** (optional). See
   [Classified cars](#classified-cars).
7. **Rehearse.** Have each counter log in on the device they will use, open
   the prova and type a few numbers. Check the lines at the bottom of
   the count page: "Armazenamento protegido" and "Cópia offline: instalada"
   (and that it does not say the one-tab protection is off, which happens
   on browsers without Web Locks). The offline copy needs https; without it
   the page still counts but cannot be reloaded with no connection. For a
   rehearsal with a whole made-up race, see
   [Simulating a race](#simulating-a-race). Then wipe the rehearsal with
   **Controle da corrida → Zerar corrida**.

### Classified cars

By default every team marked present at the event takes part. To restrict
the race to the cars that passed the earlier inspections:

1. Give the prova an input field for it, like `DESCLAS`: for example
   `APTO`, type enum, options `SIM` / `NÃO`. It is filled per car in the
   usual dashboard, with its history in the usual log.
2. On **Fontes e configurações**, set **campo de classificação**
   (`campo_classificacao`) to the field's code (`APTO`).

From then on a car takes part when it is present **and** its field says
`SIM`. A blank field means not classified. The hub has **Marcar todos os
presentes como aptos**, which fills the field for every present car that
lacks it, so nobody types 60 rows on race morning; each car changed gets a
line in the log.

Only classified cars appear as tiles, on the pit page lists, in the review
table and in the feed. A pass typed for an unclassified car is **stored,
never rejected**: the counter gets a loud warning ("carro não classificado"),
the pass does not count, and the reviewer gets the flag
`carro_nao_classificado`, where "contar mesmo assim" makes it count.
Changing a car's classification during a race recounts the race at once.

## Running beside the spreadsheets (a shadow race)

To try the system at an event while the spreadsheets stay official, set
**modo de publicação** to `desligada`. Everything works (count pages, review
screen, feed, exports) but nothing is written to the official results:
`Input.dados` is not touched, the race clock of the results page
(`prova.tempo`) is not touched, points are not recalculated. The review
screen says so in its status bar. `juiz/remote.php` keeps accepting the
spreadsheet's totals.

In this mode the dashboard leaves `VOLTAS`, `LAST` and `BEST` editable, since
the spreadsheet (or a person) owns them.

Do not run both as writers on the same prova: they would overwrite each
other's `VOLTAS`. To switch over, set the mode to `confirmadas`. The settings
page asks for confirmation, because leaving `desligada` replaces what the
spreadsheet published: every car's `VOLTAS`, `LAST` and `BEST` are written
from the count, and the race clock of the results page (`prova.tempo`) is set
to the start recorded in race control.

## Race day

### Counters

- Log in at `juiz`, click the prova. You land directly on your count page.
  Do this **while you have a connection**: the first load registers your
  device. After that the page works offline and survives a reload.
- Open the page in **one tab only**. A second tab says "já aberto em outra
  aba" and does nothing until the first is closed.
- Wait for the clock at the top to show the **Horário oficial**. Until the
  page has compared clocks with the server it says "sincronizando…". You
  can already type; those entries get their time as soon as it has.
- Choose how to type (remembered on that device): system keyboard, large
  on-screen keypad, or one tile per car.
- **Type the number as the car approaches; press Enter as it crosses the
  line.** The moment of Enter is the pass time.
- **Tiles take two taps**: tap the car as it approaches (it is shown large
  as selected; tap another to change), then tap **Passou** as it crosses
  the line. The second tap is the pass time. The switch **Um toque marca a
  passagem** (off by default, asks for confirmation, remembered on that
  device) makes a single tap on a tile record the pass at once.
- **Type every crossing, including the first one after the start.** That
  first crossing is the car's pass zero.
- Wrong number? Press **desfazer** next to it in the recent list. Do not try
  to compensate by skipping the next lap.
- The top of the page always shows: your source, the race clock, whether you
  are connected, how many entries are waiting to upload, and who else is
  counting on the same source.
- You see warnings about your own typing only. You never see what the other
  counters typed. That is deliberate: independence is what makes the
  cross-check worth anything.
- When you stop counting, press **Encerrar meu turno** (beside "Exportar";
  it asks for confirmation). It sends what is left and tells the server not
  to wait for your phone any more. The page says "Turno encerrado" only
  once the server has confirmed it.
- If the page says an entry was **recusado pelo servidor**, keep counting
  and tell the controller: the entry stays on the phone and in "Exportar
  meus dados", and everything typed after it still uploads.

### Pit lane and fueling station

The pit log page is used by the people standing at the pit lane and, when
the prova logs it (`abastecimento`), at the fueling station. Entry and exit
are usually **two people on two phones**, one at each end of the area; any
number of phones can log the same area.

- The page opens on a choice: the area (**Box** or **Abastecimento**; only
  asked when the fueling station is in use) and then **Entrada** or
  **Saída**. The choice is remembered on that device and stays on screen as
  a switch, for one person doing both.
- After that, **every tap on a car records that event**, at the moment of
  the tap.
- In Saída mode the cars currently inside are listed first and every other
  car below, under "outros"; in Entrada mode the reverse. No car is ever
  hidden: the person at the other end may have missed one.
- An exit with no entry, or a second entry with no exit, is stored and
  shown on the review screen as unpaired ("sem par"). It never blocks
  anything, and counts as a stop only once its other half exists.

A car logged in either area is left out of the pace and best-lap
calculations for that lap, a pass at the line while it is logged inside is
flagged (`no_box`, `no_abastecimento`), and its "stopped" clock restarts when
it leaves. The two areas are independent.

### Handover between counters

The person arriving opens the same source on their own device and starts
counting **before** the person leaving stops. For a lap or two both count;
the page shows each of them the other's name. The server counts each pass
once. The person leaving then presses **Encerrar meu turno**.

### Controller

- **Controle da corrida**: press **Largar agora** at the start. If the
  button was pressed early or late, enter the real start time in the field
  next to it. A start in the future is refused. When a new or corrected
  start would leave entries before it, the page says how many entries of
  how many cars and asks for confirmation. Red flag, restart and finish are
  on the same page; a restart needs a red flag in force, and a red flag
  needs the race to be running.
- **After the finish** every car crosses the line once more, and that pass
  counts; keep counting until each car has taken the flag. If a car crosses
  twice on its way in, only the first counts (`apos_encerramento`).
- **Revisão**: the review screen. Flags are listed with the ones affecting
  the top of the standings first. Select one to see a one-minute timeline
  with a lane per counter (plus the pit lane and the fueling station), the
  suggestion and its reason. Shortcuts: **A** accept the suggestion, **V**
  void the entry, and **K**, whose meaning depends on the flag and is
  always written on its button:
  - on a lone entry, a probable swap, a pass before the start, an entry of
    unknown time or an unclassified car, K is **Contar esta passagem (+1
    volta)**: it makes the pass official on that one counter's word. The
    screen asks for confirmation when the lap it creates would be
    impossibly short;
  - on a repeat there is no K: the choices are to void the second entry or
    to say it was another car;
  - on the other flags K is **Está correto, parar de avisar**: it silences
    the flag and changes no count.

  A too-short lap also has **Descartar esta passagem**, which voids every
  entry of that pass in one step. A reason can be typed with any decision.
  Every decision is in the history panel and can be undone.
- The status bar shows "calculado há N s": how old the flags and lap times
  on screen are. It turns red beyond three recalculation intervals, which
  means the server is not keeping up or the connection is poor.
- The car table shows, per car, the official laps next to each counter's
  own number of passes (laps + the pass zero); real disagreements are
  highlighted. Click a car to see its whole race and to add a pass nobody
  recorded.
- Manual fields such as `DESCLAS` are still edited in the usual dashboard
  (link on the hub). Saving them never touches the lap count, with one
  exception: changing a car's classification field recounts the race.

### What the flags mean

| Flag | Meaning | Suggestion |
|---|---|---|
| `troca_provavel` | One counter typed car X alone while two others recorded car Y, which that counter does not have | Reassign the entry to Y |
| `solitaria` | Only one counter recorded this pass | Void it if it would create an impossibly short lap; otherwise the reviewer decides (K counts it) |
| `repetida` | Same counter, same car, twice within 20 s | Double taps and handover duplicates are voided automatically; others suggest voiding the later entry |
| `primeira_passagem_tardia` | The car's first pass came much later than the field's: its pass zero was probably missed | None. Add the missing pass, or press K if the car really started late |
| `antes_da_largada` | Entries for a car shortly before the start (the start was moved, or a clock was early). Without them the car has lost its pass zero | Count it as the car's initial pass |
| `no_encerramento` | A pass whose entries lie on both sides of the finish: the counters disagree on whether it was the last racing pass or the one after the flag | None |
| `tempo_estimado` | An entry typed offline whose time had to be estimated from an earlier page load (see [How time is taken](#how-time-is-taken)). It was matched normally | None. K if the time looks right on the timeline |
| `tempo_desconhecido` | An entry whose time is only the device's own clock. It is stored and shown, and never counted automatically | None. K counts it |
| `carro_desconhecido` | The number is not a team present at the event | None |
| `carro_nao_classificado` | A pass for a car that is present but not classified | None. K is "contar mesmo assim" |
| `no_box`, `no_abastecimento` | A pass while the log says the car is in the pit lane or at the fueling station | None |
| `volta_curta` | Two official passes of a car less than 40 s apart, or less than half its expected lap; the reason says which rule applied | None. "Descartar esta passagem" removes one of them |
| `divergencia_total` | Imported paper totals differ from the official count, or do not list a car that has laps | None |

A pass recorded by two counters and missed by the third is **not** a flag.
It counts, and the miss shows in that counter's statistics. The flags are the
same in every publish mode.

### Car status

Shown on the review screen and in the overlay feed: `correndo`, `atrasado`
(more than 1.5 expected laps since its last pass or pit exit), `parado`
(more than 2), `no_box`, `abastecendo`, `fora`. A car logged in both areas
shows the one it entered last. "Expected lap" follows the pace of the field
in the last 15 minutes and the car's own speed. The clock behind it stops
during a red flag. A long gap is never treated as proof of a missed pass.

## When things go wrong

**A counter's phone loses its connection.** Nothing to do. It keeps
counting; the page shows the number of entries waiting. On the review screen
that source turns to "offline". The engine holds back any flag that depends
on what that counter might have recorded until the phone uploads. Official
counts are not affected: they never depend on who has synced.

**A counter's device dies (battery, dropped, lost).**

1. Put the counter on another device: they log in, click the prova, and
   keep counting on the same source.
2. If the dead device comes back, open the count page on it with a
   connection: everything it had recorded uploads, nothing twice.
3. If it will not come back, nothing needs to be done: after ten minutes of
   silence (`dispositivo_ausente_s`) the server stops waiting for it. Its
   unsent entries are lost and that counter has a gap, which the other two
   cover.
4. If the device works but cannot connect at all, use **Exportar meus
   dados** on it to download its full log as CSV, as evidence.

**A counter was confused for a while, or a source is gone for good.** On the
review screen, "excluir esta fonte" for the interval, or with no end time
("até segunda ordem"). When the counter is back, use **Encerrar exclusão em
h:mm:ss** on the open exclusion: it becomes an exclusion with that end, in
one step. (Undoing it instead would make the whole gap count as passes that
counter missed.) To stop using a source altogether, deactivate it on the
Fontes page: what it recorded before stays in the count.

**A counter must be removed, or one phone must be stopped.** On
**Contadores**, "remover" takes the person off the source; on the Fontes
page, "revogar" stops one device. Either way the device stops uploading at
once; its page says so, keeps its data and offers the export. **A user whose
device was revoked stays blocked on that source**: the page cannot get a new
token by itself. To let the person back in, link them to the source again on
Contadores, or press **liberar** next to their name on the Fontes page.

**A race-control button was pressed by mistake.** Every event listed on
Controle da corrida has **cancelar**, those with a time have **corrigir**,
and a cancelled event has **restaurar**.

| Mistake | Recovery |
|---|---|
| "Largar agora" too early or late | Enter the real time next to the button, or "corrigir" the start |
| "Largar agora" pressed again mid-race | The page asks for confirmation first. If confirmed by mistake, "cancelar" the new start; the previous one is back in force |
| A correction was itself wrong | "cancelar" the correction: the time before it is back in force. Or "corrigir" again |
| An event was cancelled by mistake | "restaurar" it |
| Red flag or restart by mistake, or at the wrong time | "cancelar", or "corrigir" its time |
| "Encerrar" by mistake | "cancelar" the finish; the race is running again and nothing was lost |

Cancelling the **only** start asks for a specific confirmation: without a
start every count goes to zero and the published results are cleared until
it is restored. To change a start's time, use "corrigir", not "cancelar".

Each of these is a new record; nothing is deleted, and results follow at
once.

**A phone or laptop has the wrong time, or its clock changes mid-race.**
Nothing to do: the pages do not use the device's clock. See
[How time is taken](#how-time-is-taken).

**Redis is restarted or unavailable.** Counting and results are not affected
(they live in MySQL). The review screen and the feed keep showing the last
calculated state, which is also kept in a file, and catch up on the next
recalculation. "Who is looking at this flag" and "who else is counting here"
are blank until Redis is back.

**The server is busy.** A phone that gets "ocupado" simply tries again a few
seconds later; the counter sees the queue number stay up for a moment.

**The server or the internet is down for everybody.** Counters keep counting
offline. Results freeze. When the connection returns, everything uploads and
the standings catch up within seconds.

**The count looks wrong after a correction.** On Controle da corrida,
**Reconstruir** recomputes all laps from the raw entries, and **Republicar
tudo** rewrites every car's result. Both are safe at any time.

**A team protests.** Click the car on the review screen: every entry of
every counter for that car is on the timeline, including voided ones, passes
left out after the finish, passes typed before the start, entries of unknown
time and the pass zero, with who decided what and when. The CSV exports on
Controle da corrida contain the same, plus the stops ("paradas").

**A race was wiped by mistake.** "Zerar corrida" needs an admin once the
race has a start, and it copies every row to the table `contagem_arquivo`
before deleting. Restoring from there is manual (SQL).

## Settings

Stored in `prova.params.contagem`, edited on **Fontes e configurações**,
effective immediately.

| Key | Default | Meaning |
|---|---|---|
| `modo_publicacao` | `confirmadas` | Which passes count: `confirmadas` (two sources agree, or a reviewer said so), `fonte_principal` (whatever the primary source has), `todas` (everything not voided), `desligada` (counts like `confirmadas`, publishes nothing) |
| `fonte_principal_id` | none | The source trusted by `fonte_principal` |
| `passagem_inicial` | `primeira_passagem` | Which pass is lap zero: each car's first pass, or the start (`largada`) |
| `janela_largada_s` | 0 | Only with `largada`: entries this soon after the start never count |
| `apos_encerramento` | `proxima_passagem` | After the finish, each car's next pass counts and later ones do not; `nenhuma` counts none |
| `janela_ms` | 20000 | Entries for the same car within this window are the same crossing |
| `duplicata_ms` | 5000 | A repeat from the same source inside this is a double tap, voided automatically |
| `fracao_volta_minima` | 0.5 | Below this fraction of the expected lap, a lap is "too short to be real" (at most 1) |
| `janela_ritmo_s` | 900 | How far back the field pace looks |
| `fator_atrasado` / `fator_parado` | 1.5 / 2.0 | Car status thresholds, in expected laps |
| `dispersao_max_ms` | 2000 | Sources must agree within this for a lap to qualify as best lap |
| `fonte_offline_s` | 60 | Silence after which a source is shown as offline |
| `recalculo_s` | 5 | Flags, lap times and best laps are recalculated at most this often |
| `dispositivo_ausente_s` | 600 | Silence after which a device is no longer waited for |
| `campo_classificacao` | none | Code of the prova's input field that says which cars may race (`SIM`); see [Classified cars](#classified-cars) |
| `abastecimento` | off | Whether the fueling station is logged as an area of its own |
| `permitir_simulacao` | off | Accepts simulated entries. **Only for rehearsals**; see [Simulating a race](#simulating-a-race) |

## Simulating a race

For a rehearsal without cars: the count pages and the pit page can play a
whole made-up race by themselves, with realistic mistakes, so that people
can practise on the review screen and the whole chain is exercised.

Simulated entries can only reach a prova whose setting **Permitir simulação**
(`permitir_simulacao`) is on. While it is on, every count, pit, review and
control page shows a permanent **SIMULAÇÃO** banner. **Switch it off again
and press "Zerar corrida" before the real race.**

All tabs build the same race from a **seed** (any whole number) and the
real start from race control; each plays its own part. Counters make
different mistakes from each other, and the same ones every time the same
seed is used.

**Live, compressed** (to watch flags, statuses and the results page behave):

1. On the settings page turn on "Permitir simulação" and apply the
   **Predefinição de teste** for the average lap you want, for example 60 s.
   It scales the matching window and the other time settings to that lap,
   remembers the real ones, and also switches "Permitir simulação" on.
2. Open one browser window per counter (each logged in on their source) and
   the pit page. Open the browser console (F12) in each.
3. In race control press **Largar agora**.
4. In every console run the same command:
   `contagemSimular({semente: 42, voltaMediaS: 60})`.
   It refuses to start, and says which preset to apply, when the time
   settings do not fit the lap. It never changes settings itself.
5. Review as in a race. `contagemGabarito()` prints the true lap count of
   each car so far and the mistakes that tab injected, with their times;
   `contagemGabarito({baixar: true})` downloads both as CSV.
   `contagemSimular.parar()` stops the tab.
6. Afterwards: **Restaurar configurações salvas** on the settings page,
   "Permitir simulação" off, "Zerar corrida".

**Replay at real scale** (counts, flags, suggestions, publishing and load
with a full-length race): keep the prova's real settings, record a start in
the past (about four hours ago) and run
`contagemSimular({semente: 42, voltaMediaS: 230, velocidade: 30})`. Entries
are injected thirty times faster than real time, each with the time it would
have had; a four-hour race takes eight minutes. Car status means nothing in
this mode, since the race is in the past.

The same replay runs without a browser, as a repeatable whole-race test:
`node baja-php/scripts/contagem-simular.mjs --semente 42` (see the top of
that file). It wipes the prova, replays three counters plus the pit log,
accepts every suggestion the review screen offers, and compares the published
lap counts with the answer key.

## Other sources

- **Automated systems** post to the same endpoint as the browsers. Create
  the source with type `automatica` on the Fontes page, press "criar token"
  and give the token (shown once) to the system. If its timing is
  trustworthy set its timing grade to `precisa`; its times then replace the
  human ones.
- **Paper totals**: create a source of type `totais` and paste
  `carro, voltas` lines. Totals never change the official count; they are
  compared with it and differences are flagged.

---

# Part 2 — How it works

## Data

All in MySQL, tables `contagem_*` (see `baja-php/schema.xml`). Times are
integer milliseconds since the Unix epoch, UTC.

| Table | Content | Written by |
|---|---|---|
| `contagem_fonte` | Sources | Fontes page |
| `contagem_fonte_usuario` | Which user may count on which source | Contadores page |
| `contagem_dispositivo` | Devices and their token hashes, last contact, "synced through", closed | Device registration, sync |
| `contagem_passagem` | Raw entries. **Append-only** | Sync |
| `contagem_ocorrencia` | Race control events and stops (pit lane, fueling station). Append-only | Controle page, pit log |
| `contagem_decisao` | Corrections. Append-only | Review screen, counters' "desfazer" |
| `contagem_volta` | Derived passes and laps. Disposable | Engine |
| `contagem_total` | Imported totals | Fontes page |
| `contagem_arquivo` | Rows removed by "Zerar corrida", as JSON | Zerar corrida |

The only code that deletes from the raw tables is "Zerar corrida". The raw
tables are `ON DELETE RESTRICT` towards `prova`: a prova with lap counting
data cannot be deleted by accident.

## Two paths

The server that runs this also serves the public results site and is small.
The first version recomputed the whole race on every five-second heartbeat
of every phone. Now the work is split by what it costs and how urgent it is.

```
                      ┌──────────────── FAST PATH (inside the request) ───────────────┐
phone ── heartbeat ──▶│ 1 SELECT (token) + 1 UPDATE (device row). No lock. No engine.  │
                      └────────────────────────────────────────────────────────────────┘
                      ┌──────────────── FAST PATH (inside the request) ───────────────┐
phone ── entries ────▶│ count lock (wait ≤ 2 s, else 503 "ocupado")                    │
                      │ store entries (idempotent by uuid)                             │
                      │ recount ONLY the cars in the upload   Engine::countCars        │
                      │ update those cars' rows in contagem_volta                      │
                      │ publish VOLTAS, LAST (JSON_SET) + refreshVarsAndPontos if      │
                      │ changed, in one transaction                                    │
                      └──────────────┬─────────────────────────────────────────────────┘
                                     │ answer sent; then, if due:
                      ┌──────────────▼─ SLOW PATH (nobody waits for it) ──────────────┐
heartbeat, if stale ─▶│ at most once per recalculo_s, only if something changed,      │
reviewer poll ───────▶│ one at a time (others get the previous snapshot)              │
decision (forces it) ▶│ whole race: Engine::run → flags, lap times, pace, best laps,  │
                      │ statistics → SNAPSHOT (Redis, copy in a file)                 │
                      │ then: timing columns of contagem_volta, publish BEST, and     │
                      │ CHECK the fast path: any VOLTAS/LAST that differs is fixed    │
                      │ and logged as an error                                        │
                      └──────────────┬─────────────────────────────────────────────────┘
                                     ▼
              review screen and public feed are served from the snapshot;
              race clock and car status are worked out from it at read time
```

Rules that make this safe:

- **The official count of a car depends only on that car's entries, the
  decisions, and which sources are switched on or excluded.** Not on other
  cars, not on which phones have synced, not on the pace. That is why
  recounting one car gives exactly what a full recount would
  (`CountCarsTest` checks it for many generated races).
- A lap count is never visible without its points: the write and the
  recalculation of points are one transaction, in the request that changed
  the count.
- The publisher changes only its own keys inside `Input.dados`, so a judge
  saving `DESCLAS` at the same moment is never overwritten. The entry page
  takes the same lock when it saves a laps prova.
- A change made by a person (decision, race-control button, setting) does
  both paths at once, under the lock, so its answer already shows the result.
  It runs the engine once and rewrites only the lap rows that differ.
- **The slow path checks the fast path.** When a full run finishes and
  nothing has changed since it loaded the race, its counts are the counts:
  any `VOLTAS` or `LAST` in `Input` that differs is corrected and written to
  the PHP error log (`Contagem\ScorePublisher: … Corrigido.`). That line
  means the two paths disagreed, which is a bug to investigate. Cars without
  laps are included, so a stale `BEST` is cleared.
- **A slow run can finish late, and must not undo what happened meanwhile.**
  Settings are read again once the lock is held (publishing may have been
  switched off), a snapshot is not stored over one that has seen more
  changes (a decision made meanwhile), and counts are only corrected when
  the change counter has not moved.
- **Heartbeats are almost free.** A heartbeat only counts as a change when
  its source's "synced through" mark (the earliest among the source's
  phones) moves into a new ten-second step. The snapshot's token is a hash
  of its content, so a recalculation that changed nothing is not downloaded
  again by the review screens.
- The public feed never computes; it only reads the snapshot. It sends
  `Cache-Control: public, s-maxage=2`; see the deploy notes below for the
  Cloudflare rule that makes that count.

Measured for the engine alone (see `PerformanceTest`, budgets enforced, best
of five runs): recounting one car in a full-size race takes well under a
millisecond (budget 10 ms); a full run of a real-size race (64 cars × 70
laps × 3 counters) is budgeted at 100 ms and a stress race of 39 000 entries
at 300 ms.

### Infrastructure

- PHP-FPM runs two pools (`baja-infra/baja-app/php-fpm-pools.conf`, which
  explains the sizes): `www` for everything, and `contagem` (3 workers) for
  `contagem_sync.php` only. nginx routes that one script to it. Counting can
  never be starved by the results site, nor starve it.
- `contagem_sync.php` and `resultados/contagem_feed.php` are served with
  `SKIP_AUTH`: neither uses the forum session, so an outage of the forum
  database cannot stop the counting or the feed.
- `baja-php/scripts/contagem-load-seed.php` and `contagem-load-test.mjs`
  reproduce a race day's traffic against the dev stack, with a field of 64
  cars. The load test reports worker occupancy as PHP-FPM itself measures it
  (`pm.status_path`, read inside the container by
  `scripts/contagem-fpm-status.php`), which includes the work done after an
  answer was sent.
- `contagem_sync.php` answers with a header `X-Baja-Pool` naming the pool
  that served it; it must say `contagem`.

### Deploy notes

- **Tables.** `baja-infra/mysql/migrations/2026-contagem.sql` only creates
  tables; it changes no existing one. Read its header about character sets.
- **Cloudflare.** `s-maxage` does nothing by itself: Cloudflare does not
  cache `.php` responses by default. Add a Cache Rule for the results host:
  *URI Path equals `/contagem_feed.php`* → **Eligible for cache**, Edge TTL
  **"Use cache-control header if present, bypass cache if not"**, and keep
  the query string in the cache key (it carries the event and the prova).
  Check with `curl -sI` on the feed URL: the second request within two
  seconds must answer `cf-cache-status: HIT`. Without this rule every viewer
  of the broadcast overlay reaches PHP.
- **After changing `baja-infra/baja-app/`** (the FPM pools) rebuild the image.

## The engine

`baja-php/src/Baja/Contagem/Engine/`. Pure PHP: no database, no clock. Two
entry points: `Engine::countCars()` (the official passes of a few cars) and
`Engine::run()` (the whole race).

| Step | Class | What it does |
|---|---|---|
| 1 | `DecisionApplier` | Drops voided entries, moves reassigned ones, drops entries of excluded sources, adds reviewer passes, cancels undone decisions |
| 2 | `DuplicateFilter` | Voids double taps and handover duplicates; flags other repeats |
| 3 | `PassMatcher`, `PassTimeFuser` | Groups entries per car by time (never by typing order); the pass time of each group |
| 4 | `PublishRule`, `PassRoles`, `LapBuilder` | Which groups are official passes; leaves out the start crossing and late finishers; numbers pass zero and the laps |
| — | *(up to here is `LapCounter`: all the fast path needs)* | |
| 5 | `PassTimeFuser::removeHabits`, `PaceModel` | Lap durations with each counter's habit removed; expected lap per car |
| 6 | `FlagDetector`, `SwapFinder`, `LateFirstPass` | The flags and their suggestions |
| 7 | `CarStatus`, `SourceStatsBuilder` | Status of each car now; statistics per source |

Supporting classes: `RaceTimeline` (start, red flags, finish, pit and
fueling stays; corrections and cancellations are resolved newest to oldest,
so cancelling a correction brings the original back), `StopStats` (stays per
car, for the feed), `SourceAvailability` (who was supposed to be counting,
and whether we know what they recorded), `Settings`, `PublishedValues`.

In `fonte_principal` mode the rule is applied pass by pass: where the
primary source was not supposed to be counting (excluded for that interval,
or switched off), that pass follows the agreement rule instead, so excluding
the primary does not zero the race.

### "Missed" versus "offline"

Every device calls the server every 5 seconds. When its upload queue is
empty it says so, and the server records that moment as the device's "synced
through" mark. A source can have several devices (a handover, a
replacement). **A source is synced through the earliest mark among its open
devices**: one phone's empty queue says nothing about another phone's
unsent entries. A device stops being open when it is revoked, when its
counter presses "Encerrar meu turno", or after `dispositivo_ausente_s` of
silence; it is open again as soon as it makes contact. A source with no open
device has nothing to wait for.

A source is *known* at time T only once it has synced past T plus the
matching window. Until then flags that depend on its silence are held back
and no car is declared late. The official count does not wait: a pass two
sources agree on counts at once, and a pass only one source has stays
pending until a second source or a reviewer confirms it.

### How time is taken

Phones and laptops with a wrong date, or whose clock is changed during the
race, used to move entries around. So the device's clock takes no part:

- On every successful contact the page pairs the **server's time** with its
  own **monotonic clock** (`performance.now()`, which cannot be set and only
  moves forward). That pair is its *anchor*; the contact with the shortest
  round trip wins. The time of an entry is the anchor's server time plus
  the monotonic time elapsed since. This is the **Horário oficial** the
  pages show.
- **The page uploads the final time, and the server never changes it.** The
  row also keeps, for audit only, what the device's own clock said and the
  anchor used (`ts_dispositivo_ms`, `ancora_*`).
- **The server never refuses an entry because of its time.** A time before
  the start, in the future or absurd is stored and flagged.
- Entries typed **before the page has an anchor** (opened offline) are held
  on the device and stamped when the first contact succeeds, from the
  monotonic time elapsed since each one. They are exact, only uploaded
  later.
- On some phones the monotonic clock stops while the device sleeps. Every
  second the page compares its advance with the system clock's; when they
  differ by more than two seconds the anchor is discarded and a new one is
  taken at once.

Two cases are left where the system clock is all there is, and both are
flagged for the reviewer:

| Stored as | When | What the engine does |
|---|---|---|
| `estimado` | Typed offline, and the page was **reloaded** (or the phone restarted) before it ever got an anchor. Stamped from the last anchor an earlier page load saved (at most 12 hours old) | Matched normally; its pass gets the flag `tempo_estimado` |
| `desconhecido` | The same, with no usable saved anchor: the time is the raw device clock | Stored, shown on the timeline, **never counted automatically**; flag `tempo_desconhecido`, where K counts it |

### Pass times and lap times

The time of an entry is the Enter press. The **pass time** is the median of
the times as typed (or the time of a `precisa` source). It depends only on
the group's own entries, so it is the same in the fast path and in a full
run, and never moves afterwards; `LAST` comes from it.

**Lap durations** use habit-corrected times: for each source the engine
measures its habitual offset (median of "its time minus the pass time" over
its 30 matched entries *before* the group, never after) and removes it.
Because the habit is measured from the past only, a published lap time does
not change when later entries arrive.

A consequence, by design: **a lap time is not exactly the difference of the
two pass times shown beside it.** The pass times are plain medians; the lap
uses the habit-corrected ones. The difference is typically 0.2 s and up to
about 1 s, more across a handover between counters. The review screen says
so in a tooltip on the lap time.

A lap qualifies for best lap only if both its ends are agreed by two sources
within `dispersao_max_ms` (or come from a precise source), neither end has
an open flag, it does not overlap a pit stay or a red flag, and it is not
impossibly short. With `passagem_inicial = largada`, lap 1 is measured from
the start and never qualifies. Published times are whole seconds, rounded.

## Other parts

| Folder | Content |
|---|---|
| `src/Baja/Contagem/Service/` | Loading, the two paths (`ContagemService`, `SnapshotService`), lap rows, score publishing, the public feed |
| `src/Baja/Contagem/Sync/` | Upload endpoint logic, device tokens and heartbeats |
| `src/Baja/Contagem/Access/` | Who may do what; counter links and the automatic `_CONT` permission |
| `src/Baja/Contagem/Review/` | Review state, timelines, decision rules and conflict detection, race control, sources and settings forms |
| `juiz/contagem*.php` | Thin pages and endpoints |
| `resultados/contagem_feed.php` | Public JSON feed for overlays |
| `default/js/contagem/` | ES modules: local store, sync queue, server clock, entry modes, pit page, review screen (`revisao*`), simulation (`simulacao*`), service worker |
| `scripts/contagem-*` | Load test, FPM status reader, simulation runner |

### Upload protocol

`POST juiz/contagem_sync.php` with `Authorization: Bearer <device token>`.
It needs no phpBB session.

```json
{
  "entries":    [{"uuid": "…", "car": 41, "mode": "teclado", "typing_ms": 850,
                  "ts_ms": 1760000000000, "time_quality": "exato",
                  "ts_device_ms": 1760000123456,
                  "anchor": {"server_ms": 1759999990000, "rtt_ms": 84, "age_ms": 10000}}],
  "voids":      [{"uuid": "…"}],
  "pit_events": [{"uuid": "…", "car": 41, "tipo": "box_entrada",
                  "ts_ms": 1760000000000, "time_quality": "exato",
                  "ts_device_ms": 1760000123456}],
  "now_ms": 1760000000500,
  "queue_empty": false,
  "encerrando": false
}
```

`ts_ms` is the final time, in server time (see [How time is
taken](#how-time-is-taken)); `time_quality` is `exato`, `estimado` or
`desconhecido`; `ts_device_ms` and `anchor` are kept for audit. `now_ms` is
the page's idea of the server's time right now, or null when it has no
anchor: the device's "synced through" mark moves to `min(now_ms, server
time)` only when `queue_empty` is true and `now_ms` is not null, and never
backwards. `pit_events[].tipo` is `box_entrada`, `box_saida`,
`abastecimento_entrada` or `abastecimento_saida`.

The response lists the uuids now stored, the server time (stamped when the
request arrived), the race state, the classified cars (`valid_cars`) and the
present but unclassified ones, the cars inside each area (for pit-log
devices), and whether fueling and simulation are on. Sending the same
entries again changes nothing, and never changes a stored time.

| Answer | Meaning for the client |
|---|---|
| 200 | Stored. Forget the uuids listed in `stored_uuids` and `voided_uuids` |
| 200 with `refused: [{uuid, motivo}]` | Those will never be stored (today: simulated records on a prova that does not allow simulation). Mark them "recusado pelo servidor" and move on; they stay on the device and in its export |
| 200 with `encerrado: true` | This request closed the device ("Encerrar meu turno"). The page trusts only this |
| 401 `{"erro": "token_invalido"}` | The device was revoked (or never existed). Stop uploading, keep the data, offer the export, and ask for a new token now and then |
| any other failure, including 503 `ocupado` | Not stored. Try again |

As a safety net the page also gives up on a record that five successful
answers in a row failed to acknowledge, shows a banner, and lets the rest of
its queue through.

`"encerrando": true` (with an empty queue) closes the device; a closing page
may send it with `navigator.sendBeacon`, with the token in the body as
`"token"`, the only case in which a token in the body is accepted. External
systems use the same request with `"mode": "api"`.

The token endpoint (`POST contagem_dispositivo.php`) answers 403
`{"erro": "dispositivo_revogado"}` to a user whose device on that source was
revoked, until a controller links them again or presses "liberar".

### Concurrent reviewers

The review screen polls `contagem_estado.php` every 2 seconds and is served
from the snapshot. Each snapshot has a token, a hash of its content: the
screen sends the token it has and gets "inalterado" plus the clock-dependent
parts when nothing changed. Tokens carry no order; the screen orders answers
by its own request counter.

Each flag carries a signature of the entries it is about. A decision is sent
with the signature of what was on screen and the entries the reviewer was
looking at; its target must be among them. A request that makes no sense is
refused before the lock is taken. Under the lock, against a fresh recount of
the cars concerned, the server refuses with 409 when the signature no longer matches
("os dados mudaram") or an identical decision is already in force ("já
decidido por …"). Decisions are never queued offline; if the answer to a
decision is lost, the screen says the outcome is unknown and reloads. Then
the engine runs once for the whole race and the answer carries the new state.
Two requests are stored as several ordinary decisions in one step:
`descartar_passagem` (a void for every entry of a pass) and
`encerrar_exclusao` (an undo plus a bounded exclusion).

### Public feed

`resultados/contagem_feed.php?evt=26BR&p=END`: race state, per-car laps,
last pass, last lap, best lap and status, the best lap of the race and the
last ten "new best lap" events, plus `calculado_em_ms` (when the numbers
were last recalculated). The existing `overlaydata.php` is unchanged and
keeps reading `Input.dados`.

For the live stream it also carries the stops. Per car, `box` and
`abastecimento` (null when the prova does not log the fueling station), each
`{dentro, desde_ms, paradas, total_ms, ultima_ms}`: whether the car is inside
now and since when, the number of completed stays, the total time inside
(including a stay under way, so it keeps running between recalculations) and
the duration of the last completed stay. And `ultimas_paradas`: the last ten
entries and exits of both areas across the field, newest first, each
`{area, tipo, carro, ts_ms}`. An unpaired event is listed there but is not a
stay. The same numbers per car are in the CSV export "paradas" on Controle
da corrida.

---

# Part 3 — Developing and testing

## Dev stack

A fresh `docker compose up` (see `docs/dev-workflow.md`) seeds event `26BR`
with prova `END` as a laps prova, three sources, and these users (password
`123456`):

| User | Role |
|---|---|
| `juiz1` | Controller |
| `superadmin` | Admin (needed for "Zerar corrida" once a race was started) |
| `equipe1`, `capitao2` | Counters on Contagem 1 (two people, to try a handover) |
| `equipe2` | Counter on Contagem 2 |
| `capitao1` | Counter on Contagem 3 |

To try it: log in as `juiz1`, open the prova, press **Largar agora**; open
three more browsers (or private windows) as the counters and type numbers.
Remember that each car's first pass is its pass zero. The dev host is plain
http, where browsers refuse service workers: to try the offline reload,
start Chrome with
`--unsafely-treat-insecure-origin-as-secure=http://juiz.baja.local`.

Production gets the tables from
`baja-infra/mysql/migrations/2026-contagem.sql` (see
[Deploy notes](#deploy-notes)). After changing `baja-infra/baja-app/` rebuild
the image: `docker compose build baja-app && docker compose up -d baja-app &&
docker compose restart nginx`.

## Tests

Run inside the `baja-app` container (`docker compose exec --user
"$(id -u):$(id -g)" baja-app …`), except the last two.

| What | Where | Run |
|---|---|---|
| Engine (no database) | `baja-php/tests/Contagem/Engine/` | `vendor/bin/phpunit --testsuite engine` |
| Upload, publishing, snapshot, devices, counter links, review, race control, HTTP access (dev database) | `baja-php/tests/Contagem/Db/` | `BAJA_TEST_DB=1 vendor/bin/phpunit --testsuite db` (add `-e BAJA_TEST_DB=1` to `docker compose exec`) |
| Browser logic | `baja-php/tests/js/` | `node --test "baja-php/tests/js/*.test.js"` on the host |
| Smoke | `baja-php/tests/smoke-test.sh` | on the host, against a freshly seeded stack |
| A whole simulated race against its answer key | `baja-php/scripts/contagem-simular.mjs` | on the host; wipes the prova, needs "Permitir simulação" on |
| Load, with real worker occupancy | `baja-php/scripts/contagem-load-test.mjs` | on the host, after `contagem-load-seed.php` |

The tests are written to be read as the specification. Each one says what
situation it sets up, what a human counter did wrong, and what the system is
expected to do. `Engine/Support/RaceScenario` builds small hand-written
races; `Engine/Support/SyntheticRace` generates whole races from a seed,
with known truth and configurable human error. No real race data is in the
repository.

The database suite builds a made-up event (`00NE`) for each test and removes
it afterwards; it takes a database-wide lock, so two runs at once queue
instead of trampling each other. Never run it against production.

## Choices worth knowing about

- **The pass time is the plain median; habits are removed only for lap
  durations.** With habits in the pass time, the time of a pass would depend
  on other cars' entries and on late uploads, and could not be computed by
  the per-car fast path.
- **A lone entry waits while other counters are supposed to be counting.**
  It only counts on its own when no other source is active or all others
  were excluded for that moment.
- **Lone entries are flagged as soon as one other source is known**, not
  two. With only two counters a disagreement would otherwise be neither
  counted nor shown to anybody.
- **K never adds a lap without saying so.** Where confirming a flag makes a
  pass official, the button says "Contar esta passagem (+1 volta)"; a repeat
  has no K at all.
- **Cancelling a correction of a race-control event brings the original
  back.** Events are resolved newest to oldest, like decisions.
- **Nothing is published before the first start**, so switching an existing
  prova to type `laps` cannot wipe results it already has.
- **Revoking a device keeps its user out of that source** until a controller
  links them again or presses "liberar".
- **An entry's stored time never changes, and no entry is refused for its
  time.** What looks wrong is flagged for a person.
- **A pass typed for an unclassified car is stored and flagged, never
  rejected.** A phone's car list can be stale, and storing everything is
  what answers a protest.
- **The simulation never changes settings**, and simulated records are
  refused unless the prova allows them.
