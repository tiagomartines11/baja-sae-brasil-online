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
presentes como aptos**, which fills in the field where it is **blank**, so
nobody types 60 rows on race morning; each car changed gets a line in the
log. A car whose field already says something else (`NÃO`) was decided about
by a person: it is left alone and listed separately on the hub.

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
spreadsheet's totals, **in this mode only**: on a laps prova in any other
publish mode its "cars", "raceTime" and "truncate" requests are refused
(HTTP 409) and change nothing, so the spreadsheet and the count cannot both
write the results. In `desligada`, "truncate" empties only `VOLTAS`, `LAST`
and `BEST` (the rows, with the classification and the other manual fields,
stay).

In this mode the dashboard leaves `VOLTAS`, `LAST` and `BEST` editable, since
the spreadsheet (or a person) owns them.

To switch over, set the mode to `confirmadas`; from then on the spreadsheet's
writes are refused. The settings
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
calculations for that lap, and its "stopped" clock restarts when it leaves.
The two areas are independent. A pass at the line while the car is logged
inside is flagged (`no_box`, `no_abastecimento`) and **held: it does not
count**, even when two counters agree on it, until a reviewer settles it.
There is no default: either somebody missed the exit, and the reviewer
presses **Marcar saída do box (+1 volta)**, which makes that pass (and any
later pass of the car held for the same reason) count; or the pass itself is
the mistake, and the reviewer presses **Descartar esta passagem**. One missed
exit therefore holds every later lap of that car until it is marked: the
flags are at the top of the queue ("pode estar com uma volta a menos") and
should be dealt with first. A second entry while a car is already
inside makes the OLDER entry the unpaired one (the exit in between was
missed), so a missed exit cannot produce a stay that lasts hours; stays still
open at the finish are closed there.

### Handover between counters

The person arriving opens the same source on their own device and starts
counting **before** the person leaving stops. For a lap or two both count;
the page shows each of them the other's name. The server counts each pass
once. The person leaving then presses **Encerrar meu turno**.

### Controller

- **Controle da corrida**: press **Largar agora** at the start. If the
  button was pressed early or late, enter the real start time in the field
  next to it. A start in the future is refused. Whenever an action would
  move the start past entries already typed (a second start, a correction,
  cancelling or restoring a start), the server says how many entries of how
  many cars would be left before it and asks first; the very first "Largar
  agora" of a race asks nothing. Red flag, restart and finish are on the
  same page; a restart needs a red flag in force, a red flag needs the race
  to be running, and neither is accepted after the finish.
- **After the finish** every car crosses the line once more, and that pass
  counts; keep counting until each car has taken the flag. If a car crosses
  twice on its way in, only the first counts (`apos_encerramento`).
- **Revisão**: the review screen. Flags are listed with the ones affecting
  the top of the standings first. Select one to see a one-minute timeline
  with a lane per counter (plus the pit lane and the fueling station), the
  suggestion and its reason. Shortcuts: **A** accept the suggestion, **V**
  void the entry, and **K** "confirm". What A and K do depends on the flag
  and is always written on the button; see
  [What A and K do on each flag](#what-a-and-k-do-on-each-flag). The rule
  behind it: a button that changes a car's lap count says so, and a
  decision that adds or removes a pass is never one keystroke away without
  a question. A reason can be typed with any decision. Every decision is in
  the history panel and can be undone.
- Flags that put a count in doubt right now are listed first and say so
  ("o carro 23 pode estar com uma volta a menos" / "a mais"); the rest
  follow in the order of the standings.
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

| Flag | Meaning |
|---|---|
| `passagem_proxima_demais` | Two counters agree on a pass, but it is closer to the car's previous official pass than a lap can be. **It is held: it does not count** until a reviewer says so. See [The hold rule](#the-hold-rule) |
| `dispersao_alta` | The entries of one pass are further apart than honest entries are (`dispersao_alerta_ms`, 4 s). With three counters the two that agree count and the outlier stands alone; with only two, the pass is held |
| `troca_provavel` | One counter typed car X alone while others recorded car Y at the same moment. Usually the lone entry is the typo. When the others' pass is held or impossibly short for Y and X was due, the flag says both readings and suggests the reverse: move the others' entries to X. **Both cars are always flagged** when a two-counter pass coincides with another car's entry from the remaining counter, however plausible each looks. The same flag is raised, with no suggestion, for a two-counter pass in which every counter left out a car the others recorded at that moment: each of its entries may be a typo for that other car |
| `solitaria` | Only one counter recorded this pass. It does not count |
| `repetida` | Same counter, same car, twice within the matching window. Double taps and handover duplicates are voided automatically. Not raised when both entries sit in passes other counters share: those are two candidate passes, and the later one is judged by the hold rule instead, so no suggestion can void an entry of an agreed pass |
| `primeira_passagem_tardia` | The car's first pass came much later than the field's: its pass zero was probably missed |
| `antes_da_largada` | Entries for a car shortly before the start (the start was moved, or a clock was early). Without them the car has lost its pass zero |
| `no_encerramento` | A pass whose entries lie on both sides of the finish: the counters disagree on whether it was the last racing pass or the one after the flag |
| `tempo_estimado` | An entry whose time had to be estimated (see [How time is taken](#how-time-is-taken)). It was matched normally |
| `tempo_desconhecido` | An entry whose time is only the device's own clock (or an API client that did not say how good its time is). Stored and shown, never counted or matched |
| `carro_desconhecido` | The number belongs to no team of the event |
| `carro_nao_classificado` | A pass for a car that is present but not classified. It does not count |
| `no_box`, `no_abastecimento` | A pass while the log says the car is in the pit lane or at the fueling station. **It is held**, whatever the number of counters, until the exit is marked or the pass discarded |
| `volta_curta` | Two official passes of a car closer together than a lap can be, in the cases the hold rule does not cover (a reviewer confirmed one, or the mode is `todas`). The flag names both passes |
| `divergencia_total` | Imported paper totals differ from the official count, or do not list a car that has laps |

A pass recorded by two counters and missed by the third is **not** a flag.
It counts, and the miss shows in that counter's statistics. The flags are the
same in every publish mode.

### What A and K do on each flag

From `default/js/contagem/revisao-alertas.js`, which is what the screen runs.
"Asks" means a question must be answered before anything is stored.

| Flag | A (accept the suggestion) | K | Other buttons |
|---|---|---|---|
| `passagem_proxima_demais` | no suggestion | **Contar esta passagem (+1 volta)**; asks when the lap would be impossibly short | Descartar esta passagem |
| `dispersao_alta` | void the outlying entry, when two other counters agree; none when the whole pass is held | Contar esta passagem (+1 volta): the outlier, or the held pass, becomes a pass of its own | |
| `troca_provavel` | move the lone entry to the other car; or, for the reverse reading, **Mover os N registros para o carro Y**, which asks and states both readings | Contar esta passagem (+1 volta) | |
| `solitaria` | void it, when it would make an impossibly short lap; otherwise none | Contar esta passagem (+1 volta) | |
| `repetida` | **Anular a segunda** | none: "keep both" would count the car twice | Era outro carro… |
| `primeira_passagem_tardia` | none | Está correto, parar de avisar (the car really started late) | add the missing pass from the car's timeline |
| `antes_da_largada` | **Contar como passagem inicial (+1 volta)**, always asks. Suggested only when two or more counters agree and the field's median first lap is known | the same, always asks | |
| `no_encerramento` | none | Está correto, parar de avisar | |
| `tempo_estimado` | none | Está correto, parar de avisar | |
| `tempo_desconhecido` | none | none (an entry of unknown time never counts, even confirmed) | **Adicionar passagem…** (the reviewer types the time), then void the entry |
| `carro_desconhecido` | none | Está correto, parar de avisar | reassign the entry from its chip |
| `carro_nao_classificado` | none | **Contar mesmo assim (+1 volta)** | |
| `no_box`, `no_abastecimento` | none: there is no default | none | **Marcar saída do box (+1 volta)** / **do abastecimento (+1 volta)**, which asks (without "(+1 volta)" and without a question when only one counter recorded the pass: it then still needs a second witness); **Descartar esta passagem** (asks) |
| `volta_curta` | none | Está correto, parar de avisar | **Descartar a primeira passagem** / **a segunda passagem** (asks) |
| `divergencia_total` | none | none | |

"Marcar saída" records an exit for that car one second before the pass,
attributed to the reviewer and shown on the lane as inferred. It closes the
stay: the held pass counts, so do the car's later passes held for the same
reason, and its stop statistics and status follow. It is an ordinary event:
race control lists it under "Saídas marcadas pela revisão" with "corrigir"
and "cancelar", and it can be undone from the history (the passes are then
held again).

### The hold rule

In the safe mode (`confirmadas`), with nobody reviewing, **a car is never
shown with more laps than it has run**. Two things make that so.

*Matching by closeness.* Counters' entries for one real crossing are close
together. An entry joins the pass whose entries are nearest in time, and when
a counter has two entries that could belong to the same pass, the one closer
to the other counters' entries stays and the other stands alone. A stray
typed 20 seconds before a car's real crossing therefore no longer captures
half of that crossing's entries.

*Holding a pass that comes too soon.* A pass that would count only because
two counters agree is **not counted while it is closer to the car's previous
official pass than a lap can be**. "A lap can be" is the larger of two
matching windows (40 s) and `fracao_volta_minima` (half) of that car's own
normal lap, once it has three laps. The normal lap is the second shortest of
its last five (the shortest, with exactly three): a plain median was tried
and let two long laps early in a race hold the car's next real laps one after
the other. Only the car's own passes are used, so the quick per-car recount
of an upload gives exactly what a full run gives. The held pass stays
pending, flagged `passagem_proxima_demais`, and the flag says which pass is
held and why ("a 12 s da passagem anterior"). If the pass was real (the car's
previous pass is the wrong one, say), K counts it. A pass a reviewer
confirmed or added always counts. The rule is not applied in mode `todas`.

A side effect worth knowing at the start: where the three counters type one
crossing of the pack more than 4 s apart from each other, no two agree
closely and that pass (a car's pass zero) is held with `dispersao_alta` until
a person confirms it.

What the reviewer sees for a held pass: the flag at the top of the queue with
"o carro pode estar com uma volta a menos", the pass drawn as held on the
timeline, and K reading "Contar esta passagem (+1 volta)".

**The known limit of two-of-three agreement: two counters making the same
mistake.** When two counters type the same wrong number for one crossing (41
typed as 14 by both), two witnesses agree on a pass that did not happen. If
it lands mid-lap for car 14 it is held, and the flag about the third
counter's lone, correct entry suggests moving the two entries to car 41. But
if it lands where a pass of car 14 is believable by car 14's own history (the
car is stopped on track with no pit log, is on a long lap, or has fewer than
three laps), nothing in that car's entries can tell it from a real pass: **it
counts, and car 14 is one lap over until a person acts.** Whenever a
two-counter pass coincides with another car's entry from the remaining
counter, both cars are flagged (`troca_provavel` stating both readings), and
so is a two-counter pass in which every counter left out a car the others
recorded at that moment. What cannot be flagged is the case with nothing
beside it: the third counter typed nothing, and the other two typed the same
wrong number.

`scripts/contagem-invariante.mjs` measures this. Over 300 simulated
real-scale races (64 cars, three counters, the simulation's default error
rates, wrong numbers weighted the way fingers slip on a keypad), no race had
a car above the truth for any other reason (no crossing counted twice, and
none through the pit lane), and 8 had one through a shared wrong number.

**What the safe mode costs.** Held passes wait for a person. In the same 300
races about 21 crossings per race were still uncounted at the end with no
reviewer at all, nearly all of them behind a pit exit the (simulated) pit log
missed: one missed exit holds every later pass of that car until somebody
presses "Marcar saída". Before the pit-lane hold the figure was 1.3. The
people at the pit lane exit matter more than before, and their flags are
listed first for that reason.

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
| "Largar agora" pressed again mid-race | The server asks first: whether to replace the start, and then about the entries the new start would leave before it. If confirmed by mistake, "cancelar" the new start; the previous one is back in force |
| A correction was itself wrong | "cancelar" the correction: the time before it is back in force. Or "corrigir" again |
| An event was cancelled by mistake | "restaurar" it |
| Red flag or restart by mistake, or at the wrong time | "cancelar", or "corrigir" its time |
| "Encerrar" by mistake | "cancelar" the finish; the race is running again and nothing was lost |

Cancelling the **only** start asks for a specific confirmation: without a
start every count goes to zero and the published counts are set to zero
until it is restored. To change a start's time, use "corrigir", not
"cancelar". A red flag or restart can be corrected as often as needed.

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
| `dispersao_alerta_ms` | 4000 | Entries of one pass further apart than this raise `dispersao_alta`; with only two counters the pass is held |
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
control page shows a permanent **SIMULAÇÃO** banner, and the public feed says
`simulacao: true`.

**A simulation requires publishing to be off.** Set **modo de publicação** to
`desligada` first, so that no made-up lap reaches the official results. The
console command refuses on a prova that publishes, unless the prova's id is
typed as confirmation (`confirmarPublicacao: 'END'`).

**Leaving a rehearsal**, in this order:

1. **Zerar corrida** (Controle de corrida). Simulation cannot be switched
   off, and the preset cannot be left, while simulated records exist.
2. **Restaurar configurações salvas** (settings page). It puts back the time
   settings the preset changed and switches simulation off. Every other
   setting stays as it is now: what was changed during the rehearsal (the
   publish mode, the classification field, the primary source, fueling) is
   not undone. Unticking "Permitir simulação" by hand while the preset is
   applied is refused, so the scaled-down timings cannot be left behind.
3. Set the publish mode for race day.

While the preset is applied, every controller page shows a banner saying so,
and "Largar agora" asks for confirmation.

All tabs build the same race from a **seed** (any whole number) and the
real start from race control; each plays its own part. Counters make
different mistakes from each other, and the same ones every time the same
seed is used.

**Live, compressed** (to watch flags, statuses and the results page behave):

1. On the settings page set the publish mode to `desligada` and apply the
   **Predefinição de teste** for the average lap you want, for example 60 s
   (between 5 and 600 s). It scales the matching window and the other time
   settings to that lap, remembers the real ones, and switches "Permitir
   simulação" on.
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
   `contagemSimular.parar()` stops the tab. Running the command again, or
   reloading a tab and running it again, injects nothing twice: each
   simulated record has an id derived from the seed, the start, the counter
   and its place in the plan, and the server keeps one record per id.
6. Afterwards: see "Leaving a rehearsal" above.

**Replay at real scale** (counts, flags, suggestions and load with a
full-length race): keep the prova's real time settings, record a start far
enough in the past and run
`contagemSimular({semente: 42, voltaMediaS: 230, velocidade: 30})`. Entries
are injected thirty times faster than real time, each with the time it would
have had; a four-hour race takes eight minutes. The whole race must lie in
the past: the command refuses otherwise and says how far back the start has
to be (a little over four hours for a 230 s lap). Car status means nothing
in this mode, since the race is in the past.

Two scripts run without a browser (see the top of each file):

- `node baja-php/scripts/contagem-simular.mjs --semente 42
  --confirmar-publicacao END`: the repeatable whole-race test. It wipes the
  prova, replays three counters and the pit log through the count pages' own
  client modules, checks during the race that no car is ever above the truth
  before anybody reviews, accepts every suggestion, compares every car
  crossing by crossing with the script, plays the reviewer for what no
  suggestion can settle (lone entries, missed pit exits, shared wrong
  numbers), and ends with "rebuild equals published". A difference caused by
  two counters making the same mistake is tolerated only when a flag is open
  on one of the cars that mistake left wrong (the over-counted or the
  under-counted one; a flag on a bystander does not do). It needs the mode `confirmadas`,
  since publishing is what it checks. With `--de 1 --ate 24` it runs one
  race per seed and prints how many passed, failed, and passed only through
  that tolerance.
- `node baja-php/scripts/contagem-invariante.mjs --ate 300`: the invariant
  of [the hold rule](#the-hold-rule) over many simulated races, engine only,
  no database.

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
  the PHP error log (`Contagem\ScorePublisher: … Corrigido.`), and the review
  screen shows it for a day ("a contagem rápida divergiu…"). It means the
  two paths disagreed, which is a bug to investigate. Cars without laps are
  included, so a stale `BEST` is cleared. **This check needs Redis**: it
  only runs when the change counter says nothing moved, and without Redis
  that is unknown, so the slow path then publishes `BEST` only and corrects
  no count.
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
- The monotonic clock can lose time (on some phones it stops while the
  device sleeps) but never jumps ahead. Every second the page compares its
  advance with the system clock's:
  - the system clock advanced **less**, or went backwards: the system clock
    was changed. Nothing happens; the anchor stands;
  - the system clock advanced more than two seconds **beyond** the monotonic
    clock: either the monotonic clock stalled or the system clock was set
    forward, which cannot be told apart locally. The anchor becomes
    *suspect*: the page keeps showing its clock, marked "verificando", and
    holds new entries. At the next answer from the server it compares: if
    the server's time agrees with what the anchor predicts, the anchor was
    fine and the held entries are stamped exactly; if not, a new anchor is
    taken and only the entries typed before the suspicion, still waiting to
    upload, are marked `estimado`.
- The server's answer carries when the request arrived and when the answer
  left, so the midpoint the anchor is built from leaves the server's own
  work out.

When a time is not exact, the entry says so and the reviewer is told:

| Stored as | When | What the engine does |
|---|---|---|
| `estimado` | Typed offline, and the page was **reloaded** (or the phone restarted) before it ever got an anchor: stamped from the last anchor an earlier page load saved (at most 12 hours old). Or: the monotonic clock really stalled while entries were waiting | Matched normally; its pass gets the flag `tempo_estimado` |
| `desconhecido` | No usable saved anchor: the time is the raw device clock. Also an entry whose time cannot be computed with (stored clamped), and **an API client that does not send `time_quality`** | Stored, shown on the timeline, **never counted or matched**; flag `tempo_desconhecido`, where the reviewer adds the pass with a time they type |

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
| `src/Baja/Contagem/Service/` | `ContagemService` is the one door for pages and endpoints and only delegates: `Recount` (running the engine, lap rows, scores), `SnapshotService` and `FullRunSettlement` (the slow path), `SettingsChanges` and `TestPreset`, `RaceRecords` and `InferredExits` (events and decisions), `ScorePublisher`, `PublicFeed`, loading |
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
systems use the same request with `"mode": "api"`. **An external system must
send `"time_quality": "exato"`** with each entry when it vouches for its
times: an entry without `time_quality` is stored as of unknown time and is
never counted. At most 500 entries (and 500 voids, 500 stop events) of one
request are looked at; the rest are not acknowledged and must be sent again.
A time outside 0 … 2^53 ms is stored clamped, as of unknown time, with the
value sent kept in `ts_dispositivo_ms`. A fueling event is refused
(`refused`, reason `abastecimento_desligado`) while the prova does not log
the fueling station.

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
- **In `confirmadas` mode no car is above the truth without a reviewer's
  action**, at the price of holding some real passes until a person looks
  ([the hold rule](#the-hold-rule)). A shared typo that lands a plausible
  lap after the wrong car's last pass is the known exception.
- **K and A can add a lap, and say so.** Where a button makes a pass
  official it reads "(+1 volta)", and where one key would change a count the
  screen asks first. A repeat, an entry of unknown time and a pass while in
  the pit lane have no K at all.
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
