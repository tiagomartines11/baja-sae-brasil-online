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
| Pit log | `{evento}_{prova}_BOX`, ticked by an administrator as "Registro de box" ("Registro de box e abastecimento" when the fueling station is logged) | Logs cars entering and leaving the pit lane and the fueling station. Does not open the count page; a counter does not open the pit page |
| Admin | `admin` | Only needed to wipe a race that has been started |

A controller can do everything a counter can.

## Before the event

1. **Make the prova a laps prova.** In `prova.params`, set `"type": "laps"`
   and make sure the inputs include `VOLTAS`, `LAST` and `BEST` (plus
   `DESCLAS` if the scoring formula uses it). The scoring formulas do not
   change. Existing results are not touched until the race is started.
2. **Give the controller the prova permission** (`{evento}_{prova}`) in
   Administração → Usuários, as for any other prova. Counters and the pit
   log need nothing there: they are added in step 4.
3. **Create the sources.** On the prova's hub (`juiz` → click the prova) open
   **Fontes e configurações** and create one source per counter, usually
   "Contagem 1", "Contagem 2", "Contagem 3", type `manual`.
4. **Add the people.** Open **Usuários** (on the prova's hub). Under each
   source type the forum username of the person who will count there; link
   reliefs to the source they will take over. Under **Box e abastecimento**
   add the people who will log the pit lane and the fueling station. Each
   gets their access automatically: `{evento}_{prova}_CONT` for a counter,
   `{evento}_{prova}_BOX` for the pit log. The two are separate: a counter
   does not open the pit page, and the pit log does not open a count page;
   somebody who does both is added in both places. The controller can do
   both without being listed. An administrator can also tick the pit code
   on Administração → Usuários ("Registro de box", or "Registro de box e
   abastecimento" when the prova logs the fueling station; every text of the
   "Usuários" page names it the same way).
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

1. Give the prova a **new** input field for it, for example `APTO`, type
   enum, options `SIM` / `NÃO`. It is filled per car in the usual
   dashboard, with its history in the usual log. Never use a field the
   scoring formula reads, such as `DESCLAS`: in the Enduro formula
   `DESCLAS = SIM` means disqualified, 0 points, and every car the hub
   marks as apt would score 0 (this happened at 26SE).
2. On **Fontes e configurações**, set **campo de classificação**
   (`campo_classificacao`) to the field's code (`APTO`). A field that a
   formula in `vars`, `min_max` or `pontos` reads (as `$CODE`) is listed
   but disabled, and the save is refused with the reason.

From then on a car takes part when it is present **and** its field says
`SIM`. A blank field means not classified. The hub has **Marcar todos os
presentes como aptos**, which fills in the field where it is **blank**, so
nobody types 60 rows on race morning; each car changed gets a line in the
log. A car whose field already says something else (`NÃO`) was decided about
by a person: it is left alone and listed separately on the hub. Its
question names the field, the value and the cars ("Grava APTO = SIM em 3
carro(s) …"). The button recounts the race and recalculates points in the
same step, even when no lap count moved, as the entry page does after
every save.

A prova saved before this check may already have a classification field
the formula reads. Its other settings can still be saved (refusing them
would block race control mid-race), but the hub and the Fontes page show
a red warning, the review status bar too, and the hub's button is
disabled. To fix it without ever taking a car out of the race:

1. Add the field `APTO` to the prova's inputs (`prova.params`), as above.
2. Copy the old field into it, `NÃO` included, so the same cars are
   classified under both:
   `UPDATE input SET dados = JSON_SET(dados, '$.APTO', JSON_EXTRACT(dados, '$.DESCLAS')) WHERE evento_id = '<evento>' AND prova_id = '<prova>' AND JSON_EXTRACT(dados, '$.DESCLAS') IS NOT NULL;`
3. On the Fontes page, set the classification field to `APTO`. The same
   cars race, so the recount changes nothing.
4. Give `DESCLAS` back its meaning: empty it only where the hub wrote
   `SIM`. In `input` a judge's real disqualification looks the same as the
   hub's `SIM`; the difference is in the `log` table, where the hub left
   one line per car with `"origem": "marcar todos os presentes"` (`pagina`
   is `<evento>_<prova>`). This clears the cars with such a line and no
   later line touching `DESCLAS`:
   `UPDATE input SET dados = JSON_SET(dados, '$.DESCLAS', NULL) WHERE evento_id = '<evento>' AND prova_id = '<prova>' AND JSON_UNQUOTE(JSON_EXTRACT(dados, '$.DESCLAS')) = 'SIM' AND equipe_id IN (SELECT h.equipe FROM log h WHERE h.pagina = '<evento>_<prova>' AND IF(JSON_VALID(h.dados), JSON_UNQUOTE(JSON_EXTRACT(h.dados, '$.origem')), NULL) = 'marcar todos os presentes' AND NOT EXISTS (SELECT 1 FROM log m WHERE m.pagina = h.pagina AND m.equipe = h.equipe AND m.id > h.id AND IF(JSON_VALID(m.dados), JSON_CONTAINS_PATH(m.dados, 'one', '$.DESCLAS', '$.DELETED'), 0)));`
   Any car still at `DESCLAS = SIM` afterwards is confirmed with the judges
   before it is touched. So is a car the hub marked and a judge then meant
   to disqualify: saving the same `SIM` changes nothing and leaves no log
   line, and the review page's manual fields leave none either.
5. **Controle da corrida → Republicar tudo** recalculates every car's points.

Switching to `APTO` before copying (step 3 before 2) would take every car
out of the race until the field is filled.

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

- Log in at `juiz`, click the prova, then tap your source on the list
  that opens. The list is always shown, even with a single source: it holds
  every source you are linked to and, if you also log the pit lane,
  **Registro de box**. Do this **while you have a connection**: the first
  load registers your device. After that the count page works offline and
  survives a reload. The list itself needs a connection, so while the page
  is offline **Voltar** on the count and pit pages is replaced by "Voltar:
  sem conexão" (plain text, in the footer below the keys); stay on the
  count page. It comes back with the connection.
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
  to compensate by skipping the next lap. The big box above the keys then
  says "Desfeito: carro N".
- **Could not read the number? Press "??"** as the car crosses, instead of
  guessing or typing nothing. It is in the same place in every mode: left
  of the number box (the **?** key does the same), left of the number on
  the keypad, the first tile. It records the pass with its time and no
  car; the box says "?? registrado" and "Informe o número depois".
  "??" never warns "REPETIDO". The server matches it with what the other
  counters saw, so a "??" is far better than a guess.
- **Giving a "??" its number afterwards.** A "??" row in the recent list
  has a big **Informar número** button (a "??" still without a number stays
  in the list after ten more entries). Tap it: a blue banner covers the big
  box, "Corrigindo: toque no carro" ("digite o número" in the keyboard
  modes) and under it "Registro das 14:03:07", and the keys and tiles get
  a blue frame.
  **The next car you tap or type is that "??"'s number: it is not a new
  pass and takes no time.** The mode then ends by itself; it also ends with
  **Cancelar**, after 20 s without a car or a digit (each digit typed
  starts the 20 s again; then "Correção cancelada: tempo esgotado"), or
  when you change mode. **If a car crosses while you are
  correcting, press "Passou um carro"**: it records a "??" for that crossing
  at that instant and ends the correction (the "??" buttons and "Passou"
  are disabled meanwhile, so that nothing else records by accident).
- The row then shows the car with a small **corrigido** mark ("corrigido
  (na fila)" until it is uploaded) and a smaller **Corrigir número**: you
  can correct it again, the newest number counts. **correção recusada**
  means the server will not take it (the server does not hold that entry
  as a "??" of this phone); tell the controller the number. If the phone
  could not store the correction, the box says "Correção NÃO guardada":
  tap **Informar número** again (typing the number now would record a new
  pass).
- After each entry the big box above the keys shows the number, the team
  and at most **one short warning line** ("REPETIDO há 3 s: desfazer?",
  "NÃO CLASSIF.: avise o resp.", "Número fora da lista"; with two, the most
  serious one). The whole sentence of every warning is on that entry's row
  in the recent list. Before the first entry the box says what to do in the
  mode on screen ("Digite o número", "Toque no carro").
- **Nothing above the keys and tiles ever changes height**: not the big box,
  not the status bar, not a warning banner. A box that grew by one line of
  warning moved every key down by about one keypad row, and the next tap
  landed on the car below the one aimed at (26SE). Texts too long for their
  line are cut with "…".
- The top of the page always shows: your source, the race clock, whether you
  are connected, how many entries are waiting to upload ("12 (3 sem
  horário)": three of them wait for the official time), and who else is
  counting on the same source (one name, then "+N").
- A problem with this device (blocked, storage, records refused) shows as a
  **red banner over the bottom of the status bar**, flashing when it
  appears. Tap it to read it all ("ver tudo") and "recolher" to close it;
  "Tentar de novo" is inside. A new problem closes it again so that its
  first line shows; one more refused record does not (it stays open while
  you read). Where nothing may be recorded any more, the banner is simply
  shown open.
- While the closed banner is up, it **hides the "Horário oficial" and
  "Também contando aqui" lines** of the status bar. You can still record
  (entries wait in the queue until the official time is known, and "Na
  fila" says how many). The trade-off is deliberate: the pad stays put.
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

- Log in at `juiz`, click the prova, then tap **Registro de box** (**Registro
  de box e abastecimento** when the fueling station is logged) on the list
  that opens. Somebody who also counts finds their source on the same list.
  The controller reaches the page from the hub or the bar at the top of the
  controller pages.

- The page opens on a choice: the area (**Box** or **Abastecimento**; only
  asked when the fueling station is in use) and then **Entrada** or
  **Saída**. The choice is remembered on that device and stays on screen as
  a switch, for one person doing both.
- After that, **every tap on a car records that event**, at the moment of
  the tap. The line above the cars says what the last tap did; it is always
  two lines high, so the cars never move under the finger.
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
presses **Marcar saída do box (+N voltas)**, which makes that pass (and any
later pass of the car held for the same reason) count, N being how many; or
the pass itself is the mistake, and the reviewer presses **Descartar esta
passagem**.

**A missed exit settles itself when the car comes round again.** When a
second pass that two counters agree on arrives one plausible lap after the
held one (no sooner than the car's minimum plausible lap, no later than one
and a half of its normal laps, and the car has three laps of its own to
judge by), the count assumes the car left one second before the first of
the two, and both count, as do its later passes. Nothing is stored: the
count works the assumed exit out again every time, and the stays, the car
status, the stops report and the pit-log phone's "cars inside" list all end
that stay there. An informational flag, `saida_presumida`, stays open on the
car so that somebody checks the pit log; it holds nothing. The upper bound
is what keeps two wrong numbers typed for a car that really is standing in
its pit from releasing each other, unless they happen to fall about a lap
apart. A single held pass with nothing after it, a pass only one counter
typed, and a car without three laps yet still wait for a person. A second
entry while a car is already
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
  the top of the standings first. The **timeline** (a lane per counter,
  plus race control, the pit lane and the fueling station) is always on
  screen, full width above the flags and the decision panel, and on a
  phone above every tab; see [The timeline](#the-timeline). Select a flag
  to see its suggestion and reason, and the timeline scrolls and zooms to
  it. Shortcuts: **A** accept the suggestion, **V**
  void the entry, and **K** "confirm". What A and K do depends on the flag
  and is always written on the button; see
  [What A and K do on each flag](#what-a-and-k-do-on-each-flag). The rule
  behind it: a button that changes a car's lap count says so, and a
  decision that adds or removes a pass is never one keystroke away without
  a question. "Contar esta passagem" on a chip of the timeline asks what K
  asks, including the short-lap question. A reason can be typed with any
  decision. Every decision is in the history panel and can be undone; each
  line names the entry's car and counter. **Undoing a void the counter made
  on their own phone asks first** ("O contador anulou este registro no
  próprio aparelho (Contador1, 14:03:27). Desfazer mesmo assim?"), from the
  history as from "Restaurar" on the chip: the counter usually typed it
  wrong and retyped it at once, and at 26SE undoing two such voids and
  confirming the retyped entries gave three cars a lap each. Undoing a
  "usar o tempo" from the history also asks (it confirmed the pass too, so
  the car may lose a lap), and undoing what makes a lap too short to be
  real count asks the chip's own "−1 volta" question (the car rows list
  the entries of each such pass for this). A voided
  chip's tooltip says who voided it and why.
- **Histórico de decisões** is one list, newest first, of every decision of
  the race, with what the system resolved on its own among them as
  read-only lines ("Sistema: ignorou a repetida #452 (carro 7, Contagem 1,
  toque duplo)", "Sistema: presumiu a saída do box do carro 7", "Sistema:
  atribuiu o ?? #123 de Contagem 2 ao carro 7"). A decision
  still in force carries **em vigor**; an undone one is struck through; an
  undo says what it undid ("Desfez a decisão #11: anulou a passagem #352
  (carro 3, Contagem 1)"). Filters above it combine: **Carro** (the car
  typed or the car the entry counts for now), **Contador** (a "usar o
  tempo" that joined a late entry is found under either counter),
  **Quem** (a reviewer, "contador (no aparelho)" for voids made on a
  phone, whoever was logged in there, or "automático"), **Tipo**, and
  **Só em vigor**; the heading says "12 de 81
  decisões", the system's lines counted apart ("+ 2 do sistema"), and
  **Limpar filtros** brings everything back. A system line sits at the
  moment of its entry (flags carry no time of their own); an assumed exit
  leaves the list once a reviewer confirms or discards it, as that
  decision is then listed instead. The filters are
  remembered per prova in the browser. Clicking a line (or **Ver**) opens
  its car on the timeline at the top, which the page scrolls back up to:
  the car's whole race centred on that moment, marked, at that very moment, with the flag's
  dashed line, with the line's entries outlined as a flag's are, or, for a system line, its flag. The screen holds the whole history
  (about 30 KB for the 81 decisions of 26SE, 144 KB for 300), so a filter
  sees every decision, not only a first page; past 1000 decisions only the
  newest are held and the panel says so (the exclusions, added passes and
  marked exits in force are always sent, older or not). Once it has the
  history, a new state brings only the decisions made since, and what
  they changed in the older lines (see
  [Concurrent reviewers](#concurrent-reviewers)). The panel is
  redrawn only when what it shows changes (a decision, an undo, a filter),
  not at every recalculation, so "Desfazer" does not move under the
  pointer; the ages ("agora", "há 15 s", "há 3 min") move in the status
  bar's steps. Open-ended source exclusions stay
  loud in the Fontes block and the status bar as well.
- A flag about a lone entry reaches back to a little before the car's
  previous official pass (at most one expected lap and half a minute
  back), so the lap the entry would make is on screen, and draws the gap
  in words: "76 s desde a passagem anterior; volta normal ~3:40". A flag
  reaches as far after its last entry (the late one of a
  "registro_tardio") as before its first. It opens zoomed out as far as
  needed, down to the live view's scale, to show the previous pass and
  all its entries; when even that does not fit, its last entry is kept
  in view near the right edge. Its own entries, first to last, always
  fit: on a phone it zooms out further for them, down to 3 px/s (a pair
  a minute apart on a 390 px screen), and never scrolls its first entry
  under the lane names.
- Flags that put a count in doubt right now are listed first and say so
  ("o carro 23 pode estar com uma volta a menos" / "a mais"); the rest
  follow in the order of the standings.
- The status bar is redrawn every second and **keeps its height** on a PC:
  two lines that never wrap (what does not fit is cut, the whole text is in
  the tooltip), and pills whose text has a fixed shape. Ages move in steps
  ("agora" for the first 15 s, then 15 s, 30 s, 45 s, whole minutes), in a
  slot of fixed width, with the exact age in the tooltip. While the official
  clock (re)synchronises, the official time and the race clock show dashes
  of their own width ("--:--:--"), "sincronizando…" in the tooltip, so the
  line does not shift. On a phone the bar
  wraps as needed. First line: official time, race clock, open flags,
  "publicado agora / há 30 s", "calculado agora / há 30 s", other reviewers.
  "calculado" is how old the flags and lap times on screen are; it turns
  red beyond three recalculation intervals while changes are waiting for a
  calculation (the server is not keeping up or the connection is poor; the
  state's `mudancas_pendentes`) and then counts every second. A quiet
  stretch of a running race, with nothing new typed, is not recalculated
  and stays in the normal colour.
- Second line: one chip per source, then the points. A chip says the
  source's name and one state word: **ao vivo** (green; amber with the
  delay when its uploads lag more than 15 s behind), **offline** (red, with
  for how long), **sem dispositivo aberto** (grey: shift closed or device
  revoked, nothing more expected), **nunca conectou**, **inativa**,
  **EXCLUÍDA** (open-ended exclusion), and **sem registros desde a
  largada** (red): switched on, no device open and nothing recorded since
  the start. That last one is a source that is not counting at all (26SE:
  "Contagem 3", whose only phone was revoked the night before, showed "ao
  vivo" all race); open it on a phone or switch it off. Its block under
  Fontes says the same. "· 2 disp." means two of its phones are not simply
  working (which, in the tooltip). An observer has a dashed chip and is
  never "sem registros". After the chips: "pontos suspeitos" in red when
  the classification field is one the formula reads or when a car has laps
  and every published points value is 0, and "pontos recalculados às
  HH:MM:SS" (`prova.modificado`, set by every recalculation of points; any
  other change to the prova row moves it too). The full sentences about
  the points, and "Publicação desligada", are in a red warning under the
  bar.
- The car table shows per car the official laps, an **Avisos** column and,
  per source, small grey numbers: the **difference** between what that
  source recorded and the official passes (laps + the pass zero; a paper
  total against the laps): "=", "−2", "+1". The raw count is in the cell's
  tooltip. Counters routinely miss a pass, so differences are not
  highlighted one by one; the row says what matters instead:
  **"contado acima de todos os contadores (+N)"** (amber) when the count
  has more passes than any counter recorded, which is what a lone entry
  confirmed twice looks like (26SE car 1: 38 official, 35 and 35); the
  number of **open flags** about the car (red when one of them puts its
  count in doubt: a held or pending pass; their names in the tooltip); and
  **"N volta(s) curta(s) confirmada(s)"** for laps too short to be real
  that a reviewer confirmed (listed in the tooltip, the lap count is
  underlined too). A source that recorded nothing since the start and has
  no device open gets no column; one that recorded nothing since the start
  but still has a device open (a phone that died and was never closed)
  keeps its column, greyed and not compared, as does one excluded from the
  start with no end, and one that did not count part of the race after the
  start ("fora da contagem até 2:00:00": promoted from observer, or switched
  on again without counting its time off; the state's `conta_desde_ms`),
  whose counts miss every pass before. Observers get no column, nor does
  a timing sensor (it reads no car: its column would be "−N" on every row).
- **Every column header sorts** (a button; keyboard and screen readers
  see `aria-sort`): first click in the usual direction (most laps first,
  oldest last pass first, most signals first, the counter who missed most
  first, a stopped car first), second click the other way, third click
  back to the standings. Cars without a value go last either way, and cars
  that are not classified stay at the bottom. The choice survives the
  redraws and a reload (remembered on that computer, per prova).
- Click a car to see its whole race and to add a pass nobody recorded. A
  pass added by hand too close to another one raises `volta_curta`;
  "Descartar" on that flag removes the addition.
- **Editar manualmente** on a flag is for what no button offers. On a
  "troca provável" about one stray entry it opens **Mover o registro … para
  o carro N**: saying which car that entry belongs to is what settles the
  flag (the same decision "Aceitar sugestão" stores, with a car you
  choose). **Adicionar passagem** is below it, for a pass that NOBODY
  recorded: it gives the car a lap on the reviewer's word and does not
  settle a flag about an entry. The car is filled in only where adding the
  pass is what the flag is about (a lone entry, a held pass too close to
  the previous one); on a probable swap, a wide-spread pass, a pass while
  the car is logged inside, or an unknown number, it is left empty.
- Manual fields such as `DESCLAS` are still edited in the usual dashboard
  (link on the hub). Saving them never touches the lap count, with one
  exception: changing a car's classification field recounts the race.

### The timeline

At 26SE the timeline only appeared after a flag or a car was picked,
"Fechar" threw it away, and it sat under the flag's title and buttons, so
those moved from flag to flag. Now it is a panel of its own, always there:

- **Nothing selected**: it follows the race, the last five minutes at a
  readable scale with "now" near the right edge (a blue line). Scrolling
  back loads earlier stretches as they come into view, down to ten minutes
  before the start; the header then says "parada no passado". **Agora**
  comes back to the present (and closes a flag or car that is open).
  Nothing scrolls while the pointer or the keyboard focus is on the lanes;
  with the pointer on them, a refresh of what is drawn waits for it to
  leave (a piece that fills a hole is drawn at once), so a tooltip or a
  click is not lost under a redraw. A piece that cannot be loaded says so
  in the header, with **Tentar de novo**.
- **A flag**: the same timeline scrolls and zooms to the flag's window. Its
  entries have a thick amber outline, the other entries of its car(s) a thin
  one, and every other car is dimmed. The flag's buttons wait until the
  flag's stretch was loaded again after it was opened ("Carregando a linha
  do tempo…"), as they waited for the flag's own window before.
- **A car**: its whole race ("Só o carro N", on by default), scrolled to
  its latest passes; opened from a line of the history, centred on that
  line's moment with the line's entries outlined thick amber. Switched off,
  all cars around the chip picked in its race (or the history line's
  moment, or its last pass), the car highlighted and the others dimmed. A
  whole race is loaded once; when the state moves on the header offers
  "Atualizar".
- **A chip clicked** with nothing selected opens its box in the decision
  panel, with the same actions as on a flag.

What it loads: two-minute pieces on a fixed grid (`contagem_janela.php?de=&ate=`),
kept by the page. A piece is asked again when the state's version changed,
and only while it is in view: at most every 5 s for the piece of "now" and
the one before it, every 30 s for older ones (each piece costs the server a
recount of every car typed in it, and the past rarely changes). The
server keeps each piece's answer for 5 s in Redis under the prova's change
count, so every review page asking for the same piece in that time shares
one recount, and any upload, decision or setting change gives a fresh one.
A piece recounts the cars typed in it plus the cars its own entries were
moved to; a typo fixed elsewhere in those cars' races does not pull more
cars in. A reviewer's own decision reloads what is on screen at once, and a flag's
pieces are loaded afresh when it is opened. At most half an hour of
pieces is kept on screen. The lanes have a fixed height and scroll inside,
and the header is one line, so nothing below moves when a piece arrives.

What a chip tells (the legend under the timeline says the same):

| Look | Meaning |
|---|---|
| green | an official pass |
| struck through, red border | voided by a reviewer |
| struck through, grey, "C" | voided by its own counter on the phone (usually a typo retyped at once); the tooltip names who and why |
| struck through, dotted | a repeat the engine voided by itself |
| blue corner | counts on a reviewer's word (`confirmado_por_revisor`) |
| blue bar along the bottom | the entry that times the pass ("Usar o tempo", `tempo_escolhido_id`) |
| amber, thick border | a lap too short to be real that counts because a reviewer vouched for it (`volta_curta_confirmada`); its box offers the undo first, which asks: **Desfazer a confirmação (−1 volta)**, **Desfazer o "usar o tempo"** when the reviewer chose its time, or **Remover a passagem adicionada** when a pass a reviewer added joined it |
| dark dotted border | one counter alone, not counting yet |
| teal, italic | an observer source's entry (compared, never counted); a click shows its picture |
| purple, dashed, "??" | a pass recorded without a number that nobody gave a car yet; never dimmed when another car is in focus (it may be that car) |
| car, "auto" in a purple corner | a "??" the engine gave that car by itself (`atribuida_automaticamente`); the tooltip says why ("atribuído automaticamente ao carro 7: Contagem 1 e 2 leram o carro 7 a 0,4 s") |
| car, "C" (not struck through) / "R" | a "??" its own counter gave a car on the phone / a reviewer did |
| "S" in the lower left corner | a timing sensor's mark times this pass (`grupo.tempo_do_sensor`) |
| sensor lane: green tick / faint tick | a mark that times a pass ("marca do sensor: tempo desta passagem"; picking it, or a chip of its pass, outlines the other) / a mark with no pass ("marca sem passagem") |
| amber number in the upper left corner | on a "??" flag: the entries of the cars it may have been, numbered as their buttons |

A counted chip's tooltip gives the lap number and the lap time, as the car
table times it: "volta 22 · 1:15" (`grupo.volta_ms`).

A timing sensor's mark is in the sensor's lane. One that times a pass has
`marca_do_sensor`, the car of that pass, and the same `grupo` as the
counters' entries of the pass; that group says `tempo_do_sensor` (its time
is the mark) and `marca_sensor_id`. A mark that times no pass is out of
use (`motivo_fora_de_uso` 'marca_do_sensor'). A piece of the timeline, or a
car's timeline, shows each mark with its pass even when that pass's car
has no entry in the piece (the piece recounts the cars of the marks'
trains too). Only a mark near the piece's edges is looked at that way: the
piece recounts every car typed in it, so a mark well inside it that times
none of them (a false trigger) times no pass, and costs a poll nothing
more.

### What the flags mean

| Flag | Meaning |
|---|---|
| `passagem_proxima_demais` | Two counters agree on a pass, but it is closer to the car's previous official pass than a lap can be. **It is held: it does not count** until a reviewer says so. See [The hold rule](#the-hold-rule) |
| `dispersao_alta` | The entries of one pass are further apart than honest entries are (`dispersao_alerta_ms`, 4 s). With three counters the two that agree count and the outlier stands alone; with only two, the pass is held |
| `troca_provavel` | One counter typed car X alone while others recorded car Y at the same moment. Usually the lone entry is the typo. When the others' pass is held or impossibly short for Y and X was due, the flag says both readings and suggests the reverse: move the others' entries to X. **Both cars are always flagged** when a two-counter pass coincides with another car's entry from the remaining counter, however plausible each looks. The same flag is raised, with no suggestion, for a two-counter pass in which every counter left out a car the others recorded at that moment: each of its entries may be a typo for that other car |
| `solitaria` | Only one counter recorded this pass. It does not count. The flag names the car's previous official pass, and the timeline of the flag reaches back to it |
| `registro_tardio` | Two counters each recorded the car alone, further apart than the matching window but too close together to be two passes (an impossibly short lap, and no more than two matching windows apart). Probably one crossing, one counter typing late: seen at a race, 23 s, with each entry flagged `solitaria` with a "plausible" lap until this flag existed. Not raised when the other entry is in a pass the counters agree on (that is a stray). In `confirmadas` neither entry counts until the reviewer says whose time is right (the car may be a lap short); in `todas` both count (a lap too many); in `fonte_principal` the primary's may count alone (the count is right, only perhaps mistimed). `contagem_em_duvida` and each time's `voltas` (+1, −1, 0) say which |
| `repetida` | Same counter, same car, twice within the matching window. Double taps and handover duplicates are voided automatically. Not raised when both entries sit in passes other counters share (those are two candidate passes, and the later one is judged by the hold rule), nor when the second entry sits in a widely spread group with another counter's late entry (that group is held with `dispersao_alta` and no suggestion): a suggestion never voids an entry another counter's entry may depend on |
| `primeira_passagem_tardia` | The car's first pass came much later than the field's: its pass zero was probably missed |
| `antes_da_largada` | Entries for a car shortly before the start (the start was moved, or a clock was early). Without them the car has lost its pass zero |
| `no_encerramento` | A pass whose entries lie on both sides of the finish: the counters disagree on whether it was the last racing pass or the one after the flag |
| `tempo_estimado` | An entry whose time had to be estimated (see [How time is taken](#how-time-is-taken)). It was matched normally |
| `tempo_desconhecido` | An entry whose time is only the device's own clock (or an API client that did not say how good its time is). Stored and shown, never counted or matched |
| `carro_desconhecido` | The number belongs to no team of the event |
| `carro_nao_classificado` | A pass for a car that is present but not classified. It does not count |
| `no_box`, `no_abastecimento` | A pass while the log says the car is in the pit lane or at the fueling station. **It is held**, whatever the number of counters, until the exit is marked, the pass discarded, or a second agreed pass arrives a lap later |
| `saida_presumida` | Informational: the log had the car inside, the counters agreed on two passes a lap apart, and the count assumed the exit and counted both. Nothing is held |
| `volta_curta` | Two official passes of a car closer together than a lap can be, in the cases the hold rule does not cover (a reviewer confirmed one, or the mode is `todas`). The flag names both passes. A lap that is impossibly short and counts on a reviewer's confirmation is not reopened, but it is marked (`volta_curta_confirmada`, below) |
| `divergencia_total` | Imported paper totals differ from the official count, or do not list a car that has laps |
| `numero_ilegivel` | A pass recorded without a number ("??", or a timing sensor's mark nobody else recorded) whose car the engine could not settle by itself. It lists the candidates (`candidatos`: car, why, score, the crossing's time), most likely first. See [Passagens sem número](#passagens-sem-número--e-sensor-de-tempo) |
| `numero_atribuido` | Resolved by the system, for the record: a "??" the engine gave a car by itself, with what it matched (`atribuicao`) |

**A short lap a reviewer confirmed** stays visible: the review screen
marks it on the car's row and on the timeline ("volta curta confirmada"),
and the chip's box offers to undo what vouched for it first ("Desfazer a
confirmação", or the "usar o tempo" or the added pass behind it).
At the race, a reviewer undid two voids and confirmed three entries in four
seconds, and three cars were one lap over with nothing open.

A pass recorded by two counters and missed by the third is **not** a flag.
It counts, and the miss shows in that counter's statistics. The flags are the
same in every publish mode.

### What A and K do on each flag

From `default/js/contagem/revisao-alertas.js`, which is what the screen runs.
"Asks" means a question must be answered before anything is stored.

| Flag | A (accept the suggestion) | K | Other buttons |
|---|---|---|---|
| `passagem_proxima_demais` | no suggestion | **Contar esta passagem (+1 volta)**; asks when the lap would be impossibly short | Descartar esta passagem |
| `dispersao_alta` | void the outlying entry, when two other counters agree; none when the whole pass is held | Contar esta passagem (+1 volta): the outlier, or the held pass, becomes a pass of its own, timed by the middle of its entries | On a held pass: **Usar o tempo de Contagem N (h:mm:ss)** for each entry (keys 1, 2, 3; asks): the pass counts at that entry's time |
| `troca_provavel` | move the lone entry to the other car; or, for the reverse reading, **Mover os N registros para o carro Y**, which asks and states both readings | Contar esta passagem (+1 volta) | **Editar manualmente → Mover** the stray entry to a car you choose |
| `solitaria` | void it, when it would make an impossibly short lap; otherwise none | Contar esta passagem (+1 volta); asks when the lap would be impossibly short | |
| `registro_tardio` | none | none: it would count one entry and leave the other to make a short lap | **Usar o tempo de Contagem N (h:mm:ss)** for each of the two entries (keys 1, 2; asks): the pass counts once at that time, and the other entry joins it. The button says "(+1 volta)" in `confirmadas`, "(−1 volta)" in `todas` (both counted) and nothing when one already counts; the short-lap question is about the lap at the chosen time. Joining entries further apart than two matching windows is refused |
| `repetida` | **Anular a segunda** | none: "keep both" would count the car twice | Era outro carro… |
| `primeira_passagem_tardia` | none | Está correto, parar de avisar (the car really started late) | add the missing pass from the car's timeline |
| `antes_da_largada` | **Contar como passagem inicial (+1 volta)**, always asks. Suggested only when two or more counters agree and the field's median first lap is known | the same, always asks | |
| `no_encerramento` | none | Está correto, parar de avisar | |
| `tempo_estimado` | none | Está correto, parar de avisar | |
| `tempo_desconhecido` | none | none (an entry of unknown time never counts, even confirmed) | **Adicionar passagem…** (the reviewer types the time), then void the entry |
| `carro_desconhecido` | none | Está correto, parar de avisar | reassign the entry from its chip |
| `carro_nao_classificado` | none | **Contar mesmo assim (+1 volta)** | |
| `no_box`, `no_abastecimento` | none: there is no default | none | **Marcar saída do box (+N voltas)** / **do abastecimento (+N voltas)**, N being the held passes that start counting; it asks (no parenthesis and no question when only one counter recorded the pass: it then still needs a second witness); **Descartar esta passagem** (asks) |
| `saida_presumida` | none | none: hiding the flag would leave the log wrong | **Confirmar saída** (asks nothing: it changes no count); **Descartar esta passagem** (asks) |
| `volta_curta` | none | Está correto, parar de avisar | **Descartar a primeira passagem** / **a segunda passagem** (asks) |
| `divergencia_total` | none | none | |
| `numero_ilegivel` | **Atribuir ao carro N** (`reatribuir`), N being the closest car another counter read, only when that car's pass already counts (the "??" joins it, no count changes). None when that reading is a lone one (the "??" would make it count: the reviewer picks the car's button, which asks), for a sensor's mark, or when no counter read a car near. On the screen A is the first candidate's button, marked A | **Deixar sem carro, parar de avisar** (changes no count) | one button per candidate, keys **1** to **6**, and "Outro carro" for any car (each a `reatribuir`, asked first when it adds a pass, see [below](#on-the-review-screen)); **Anular (não passou carro)** on V (for a mark: "Anular a marca (não foi passagem)") |
| `numero_atribuido` | none (resolved by the system) | none | **Não é o carro N…** opens the other cars read near it (keys 1 to 6), "Outro carro" and "Anular (não passou carro)": a reviewer's decision wins over the engine's |

"Marcar saída" records an exit for that car one second before the pass,
attributed to the reviewer and shown on the lane as inferred. It closes the
stay: the held pass counts, so do the car's later passes held for the same
reason, and its stop statistics and status follow. It is an ordinary event:
race control lists it under "Saídas marcadas pela revisão" with "corrigir"
and "cancelar", and it can be undone from the history (the passes are then
held again).

An exit the count assumed is shown as **saída presumida**: a dotted mark on
the review lane where it ends the stay, and a list "Saídas presumidas" in
race control, above the marked ones. It stays derived, worked out again on
every count, until somebody presses **Confirmar saída** (on the
`saida_presumida` flag or in race control). That stores it as an inferred
exit like the one "Marcar saída" writes: the flag goes away, the count does
not change, and the exit moves to "Saídas marcadas pela revisão". Cancelling
it there puts the log back to "inside"; if the counters still show the car
lapping, the count assumes the exit again and the flag returns.

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
races 2.9 crossings per race were still uncounted at the end with no
reviewer at all. Before the pit-lane hold the figure was 1.3; with the hold
and no way out but a reviewer it was 20.7, because one exit the (simulated)
pit log missed held every later pass of that car. The assumed exit described
under [Pit lane and fueling station](#pit-lane-and-fueling-station) brought it back
down: what still waits is the one pass before the car's next lap proves it
left, and the cars that never come round again.

### Car status

Shown on the review screen and in the overlay feed: `correndo`, `atrasado`
(more than 1.5 expected laps since its last pass or pit exit), `parado`
(more than 2), `no_box`, `abastecendo`, `fora`. A car logged in both areas
shows the one it entered last. "Expected lap" follows the pace of the field
in the last 15 minutes and the car's own speed. The clock behind it stops
during a red flag. A long gap is never treated as proof of a missed pass.

**Two yardsticks, not one.** The *field pace* is the median of the clean
laps the whole field finished in the last `janela_ritmo_s` (unknown under
eight laps); a car's *expected lap* is that pace times how the car compares
with the field (the median of its last six laps against the pace, 1.0 until
it has three). They drive the car status above, the "too short to be real"
test (`volta_curta`, and the lone-entry and swap suggestions that lean on it) and which
laps may be a best lap. They never decide whether a pass counts: holding a
pass that comes too soon, and settling a missed pit exit by the car's next
lap, use **the car's own laps** ([the hold rule](#the-hold-rule)), so the
count of one car never depends on the others. The pace is shown on Controle
da corrida ("Ritmo da corrida": the pace now, the laps behind it, and a chart
of it minute by minute since the start, against race time written 1h30; a
minute with too few laps is a gap in the line, and the filled dot of the
current pace is left out while the pace is unknown now) and is in the
[public feed](#public-feed).

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
Fontes page: what it recorded before stays in the count. Activating it
again does **not** bring back what its phone sent while it was off: that
period is kept as a period off (`periodos_fora`). To count it after all,
tick "ao reativar, contar também o que ela enviou enquanto estava
desativada" when activating it. A source can also be created switched off
("criar desativada"), so nothing it sends counts until it is switched on.

**A counter must be removed, or one phone must be stopped.** On
**Usuários**, "remover" takes the person off the source (or, under Box e
abastecimento, off the pit log); on the Fontes page, "revogar" stops one
device. Either way the device stops uploading at
once; its page says so, keeps its data and offers the export. **A user whose
device was revoked stays blocked on that source**: the page cannot get a new
token by itself. To let the person back in, link them to the source again
(or add them to Box e abastecimento again) on Usuários, or press **liberar** next to their name on the Fontes page.

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
| `fator_atrasado` / `fator_parado` | 1.5 / 2.0 | Car status thresholds, in expected laps. A lap longer than `fator_parado` expected laps is never a best lap |
| `dispersao_max_ms` | 2000 | Sources must agree within this for a lap to qualify as best lap |
| `dispersao_alerta_ms` | 4000 | Entries of one pass further apart than this raise `dispersao_alta`; with only two counters the pass is held |
| `fonte_offline_s` | 60 | Silence after which a source is shown as offline |
| `recalculo_s` | 5 | Flags, lap times and best laps are recalculated at most this often |
| `dispositivo_ausente_s` | 600 | Silence after which a device is no longer waited for |
| `campo_classificacao` | none | Code of the prova's input field that says which cars may race (`SIM`); never one the scoring formula reads; see [Classified cars](#classified-cars) |
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
   `taxas: {ilegivel: 30, ilegivel_corrigido: 500}` adds "??" (per thousand
   crossings, off by default) and has the counter give the number to some
   of them (per thousand "??", 5 to 15 s later). Both draw from a stream of
   their own: every other mistake of the seed stays the same.
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
- `--taxas '{"ilegivel": 20}'` makes the counters press "??" on 2 % of the
  crossings (it needs a count page that records "??"), and `--sensor 1`
  adds a timing sensor that marks every crossing to the millisecond and
  posts like an automatic system (`--taxas-sensor '{"perdida": 20,
  "falsas_por_hora": 60}'`: misses per thousand, false marks per hour).
  The set-up helper makes the sensor source, taking part, the first time.
  `"ilegivel_corrigido": 300` has the counters give the number afterwards
  to 30 % of their "??". In the checks a "??" its counter gave the right
  number is a counter that typed the crossing right; one left without a
  number is not (its car is the engine's reckoning, or a reviewer's). With
  a sensor the runner also says how many counted passes its marks time
  (the `tempo_do_sensor` column of the laps export).
- `node baja-php/scripts/contagem-invariante.mjs --ate 300`: the invariant
  of [the hold rule](#the-hold-rule) over many simulated races, engine only,
  no database.

## Other sources

- **Automated systems** post to the same endpoint as the browsers. Create
  the source with type `automatica` on the Fontes page, press "criar token"
  and give the token (shown once) to the system. A new automatic source
  starts as an **observer**: nothing it sends counts until it is promoted
  (see [Visão computacional](#visão-computacional)). If its timing is
  trustworthy set its timing grade to `precisa`; once it counts, its times
  replace the human ones (the page asks first).
- **A timing sensor**: type `sensor`, with an API token like an automatic
  system. It marks the moment of every crossing and never the car; it
  never counts a pass, and its marks are the time of the passes. See
  [Passagens sem número (??) e sensor de tempo](#passagens-sem-número--e-sensor-de-tempo).
- **Paper totals**: create a source of type `totais` and paste
  `carro, voltas` lines. Totals never change the official count; they are
  compared with it and differences are flagged.

### Visão computacional

A camera reading car numbers is a question mark until it has been measured
at a real race. The app is ready for one, and none of this shows or costs
anything while no source is an observer and no source has an image address.

**Observer role.** An `automatica` source has a *papel*: `votante` (counts,
like a counter) or `observadora`. New automatic sources start as
observers. An observer's entries are stored and shown on its lane, marked
"fonte observadora", but they are set aside in the same place as the
entries of a switched-off source: they are in no pass group, set no pass
time (even with `precisa`), raise no flag, and the observer is never
*expected*, so it can neither miss a pass nor hold one back while its
phone is offline. It still turns "offline" when it stops sending. A race
with an observer counts exactly what the same race without it counts; a
test checks that.

Instead, after counting, `ObserverComparator` measures it against the
official passes:

| Result | Meaning |
|---|---|
| acerto | paired with an official pass of the same car within `janela_ms` (closest pairs first, each entry and pass used once) |
| leitura trocada | no pass of its car, but another car's official pass within 2 s that it has no entry for: it read that number wrong (car X read as Y) |
| falso positivo | neither: it saw a car that did not pass |
| falta | an official pass, while it was observing and had synced past it, with no entry |
| ilegível | a "??" (it saw a car and could not read it); `ilegiveis_com_passagem` counts those within 2 s of an official pass it has nothing else for, which is then no `falta`: that is how often it fails to read, as opposed to how often it is blind |

plus the median offset from the pass time and the 90th percentile of its
size. A timing sensor on trial reads no car: each mark is paired with the
nearest official pass of any car within `dispersao_alerta_ms`, a paired one
is a hit (its offset measured), an unpaired one a false positive, and a pass
with no mark a miss. The reference is the official passes only. After the finish cars
still cross the line on their way to the pits, so an entry from after the
finish that pairs with no official pass is left out instead of being a
false positive. Before the finish a real crossing the rules did not count
(a lap too short to be possible, a pass only one counter saw) does show as
a false positive.

The review screen shows this in the source's block (instead of a
counter's numbers, and with no column in the car table), the Fontes page
under the source, and Controle de corrida offers it as a CSV, one row per
entry and per missed pass ("Fonte observadora x contagem oficial").

**Promotion.** Choosing `votante` on the Fontes page asks first, and the
promotion counts **from that moment on**, never backwards: the time it
observed is stored as a period off marked as observed, so its entries from
then are still "fonte observadora" on the timeline and its comparison stays
(the review block shows it below the counter's numbers, labelled "antes da
promoção"). Making a votante an observer keeps the votes it cast before
(like switching a counter off). An observer cannot be the primary source
of "fonte principal" mode, and that mode's primary source cannot be made
an observer. In the other modes the primary has no effect, so an observer
left stored there does not stop the settings form from saving. With
timing `precisa`, the page also asks before the source starts to count,
because its time then becomes the pass time and the reference for every
counter's habit.

**What the system sends.** The upload protocol, with `"mode": "api"`.
**`"time_quality": "exato"` is mandatory**: an entry without it is stored
as of unknown time and never counted nor compared. Two optional fields per
entry, stored and shown, never computed with:

- `imagem`: an opaque id of the picture behind the entry, up to 64
  characters of `A-Z a-z 0-9 . _ -` (not starting with a dot). Anything
  else is dropped; the entry is still stored.
- `confianca`: how sure the system was, a number from 0 to 1.

For its misses to be measured the system must also report how far it has
synced: `queue_empty: true` with `now_ms`, also as a heartbeat with no
entries (see [Upload protocol](#upload-protocol)). An official pass is only
a *falta* once the observer is synced past it; a system that never sends
that mark shows 0 misses and looks perfect. "sincronizado até" in its
review block shows whether it does.

**Pictures.** With an *endereço das imagens* (`imagem_base_url`) set on
the source, each entry with an `imagem` gets `imagem_url` = address + id
in the timeline (the address must have a path, at least `http://box/`, so
an id cannot run into the host name; one saved without gets its "/"), and clicking its chip shows the picture with its
confidence. The picture is served by the system itself, never by this
server, and never appears in the public feed. Expect it to be a box beside
the track on the local network, and plan for:

- **Mixed content.** The review page is https; a picture from `http://`
  is blocked or warned about by browsers. Give the box a real hostname and
  a certificate (Let's Encrypt with the DNS-01 challenge works for a
  machine the internet cannot reach).
- **Private network access.** Chrome restricts pages from the internet
  loading from local addresses (192.168.x, 10.x). A real hostname and https
  avoid most of it.
- **Off the track network** the picture simply does not load. The screen
  then says "imagem só na rede local", and the "abrir em outra aba" link
  remains as the fallback (an http link opened on its own is allowed).

## Passagens sem número (??) e sensor de tempo

A car goes by and the counter cannot read its number: mud on the plate, a
bunch of three, the sun. Before, the counter typed nothing and was charged
a miss. Now there is a third answer, **"??"**: "a car crossed here, I could
not read which". A camera that cannot read a plate says the same, and a
**timing sensor** at the line (a beam, a loop) says nothing else: it marks
the moment of every crossing, precisely, and never the car.

**What is recorded.** An entry with no car (`contagem_passagem.equipe_numero`
NULL), at the moment of the press, like any other. Nothing is lost: the
timeline shows it, the history and the CSV keep it, and its counter is no
longer charged a miss for that crossing once it has a car. The source's
statistics count them (`ilegiveis`, and `ilegiveis_atribuidas` for those the
engine applied by itself).

**Which car it was** is worked out on every count, not stored
(`UnreadAssigner`, step 1b of [the engine](#the-engine)). For each source
on its own, its "??" are put against the crossings the OTHER sources read
around them: per car, their entries no more than a matching window apart,
timed by their median, within `dispersao_alerta_ms` (4 s) of the "??". A
crossing in which the same source typed a number is no candidate: a counter
cannot have typed car 7 and pressed "??" for the same crossing of car 7.
Several "??" of one source close together (a bunch) are solved together,
one crossing each, the pairing with the smallest total time offset first.

**What is applied by itself.** A "??" gets its car with nobody asked when
the answer is clear and cannot change a count:

- the best answer is clearly better than the next: without it the total
  offset grows by at least 2 s and by at least the "??"'s own offset (the
  next is twice as far), or there is no other answer at all; and
- at least two other sources read that crossing, and their readings agree
  by themselves: grouped as PassMatcher groups them, without the "??" they
  are one agreed pass (within `dispersao_alerta_ms`), and the "??" joins
  that same pass, leaves it agreed and changes no other group. So the pass
  counts with or without the "??". Two readings six seconds apart (a typo
  for the car next to it, and the real car later) are a dispersed group
  that waits for a person; a "??" between them would pair with one of them
  and count a lap the car never drove, so it is flagged instead.

Then the "??" is that counter's entry for the car (its vote, its timing,
no miss), and the review screen's list of what the system resolved says so
(`numero_atribuido`, with the crossing's entries and sources and the
offset). With a single reading the "??" would be the vote that makes the
pass count, and that reading may be a wrong number: one counter types 29
when 22 crosses, another misses it, the third presses "??". Applied, that
gave car 29 a lap it never drove, in one generated race in sixteen. So a
"??" never decides a count on its own; a person does.

**What is flagged.** Every other "??" (once the other counters have synced
past it) raises `numero_ilegivel`, with the cars it may have been, most
likely first: `outros_contadores` (another source read it nearby; the
suggestion, A, is the closest of these when the "??" would join a pass that
already counts, and none otherwise: with a lone reading A would make that
reading count, and in simulated races (seeds 2, 3 and 7) the closer lone
reading was a wrong number, so accepting it gave a car a lap it never drove), `carro_esperado` (the car was due at
the line then, by its last pass and its expected lap, and has not passed
since) and `volta_faltando` (the car's passes before and after are about
two laps apart, and the "??" about a lap from each). The reviewer picks the
car ("trocar de carro", a `reatribuir`) or voids the entry; "confirmar"
only stops the warning. A decision on the entry, by a reviewer or by its
own counter on the phone, always wins over the engine's reckoning, and
confirming the car the engine chose makes it a person's word.

**The counter fixes it on the phone.** "Ah, it was 17": the page sends
`fixes: [{uuid, car}]` for one of its own "??", and the server stores a
`reatribuir` with the counter as its origin, like a void from the phone.
Only for an entry of that phone's own source that was stored with no car;
see [Upload protocol](#upload-protocol) for what is refused. The counter's
newest fix counts: 17, then 71, then 17 again leaves it car 17.

**Measured** (`UnreadPassSyntheticTest`, and the same races at full size:
64 cars, four hours, 230 s laps, the usual mistakes, each counter pressing
"??" on 2 % of the crossings it would have typed right): with **three
counters**, 92 % of the "??" were applied by themselves, all of them to the
car that really crossed (1439 of 1439 over eight races, without the
race-day trouble); the other 8 % were
flagged, each with the right car among its candidates. With **two
counters** every "??" is flagged (the other one reading is never enough),
with the right car among the candidates 99.8 % of the times there was one. No car ever had
more laps than it drove before a reviewer acted. A race with no "??"
counts exactly as before, and costs nothing more.

### The timing sensor

A source of type **`sensor`** ("Sensor de tempo: marca só o horário da
passagem, nunca o número"), created on the Fontes page. Its system posts
like an automatic one, with an API token ("criar token"); every entry it
sends is stored with no car (a car it sends anyway is ignored, not refused,
because the moment is still good), and its timing is always `precisa`. A
new sensor starts as an **observer**, like a camera: its marks are only
compared with the official passes (the review block and the Fontes page:
how many coincide with a pass, the passes with no mark, the marks with no
pass, and the offset). Promoted to votante (the page asks, in a sensor's
words), its marks start to time passes: each matched mark is the official
time of its pass. Once a source has recorded anything,
its type cannot change to or from `sensor` (the page says why): its rows
would change meaning, every number ignored or every mark a "??" to review.
Create a new source instead.

**A sensor never counts a pass, but it times it.** A mark matched to a
pass is the pass's timing-only witness (`PassGroup::$marcaSensor`): it is
no vote, nobody waits for it, and a pass without one is no miss of anybody
but the sensor. Its time **is the pass time**: `LAST`, the lap row's time,
the order of two cars at the line, the hold rule's "too soon", and the
laps on either side (precisely timed, so they may be best laps). The
counters' habits are measured against it. A precise source in the pass,
or a reviewer's "usar o tempo", still comes first.

**How a mark finds its pass.** The marks are put against the *crossings*
the counters read: per car, its readings (a "??" the engine gave the car
included) no more than a spread (4 s) apart, at their earliest reading,
since a counter presses Enter as the car is on the line or after. A mark
and a crossing within 2.5 s of each other belong together, and so does
everything within 2.5 s of either: a **train**. Each train is decided on
its own (`SensorTrains`, `UnreadAssigner::matchMarks`):

- one mark, one crossing (most trains): that is its pass, unless the mark
  is more than 1 s after the crossing's first reading
  (`MARCA_DEPOIS_DA_PRIMEIRA_LEITURA_MS`): a counter presses Enter as the
  car is on the line or after, so a mark seconds after every counter is a
  false trigger beside a crossing whose own mark was missed. Such a mark
  stays untimed ("ambígua");
- otherwise by the times: each counter's own delay within the train is
  taken out of the crossings' times, the crossings are moved by the common
  lag that lines them up best with the marks, and a mark gets the crossing
  it is paired with when every other answer (another pairing, another lag)
  is clearly worse: by 0.5 s in a train with as many marks as crossings,
  by 1 s otherwise (a false trigger or a missed car may be in it);
- where the times leave marks unclear, **the counters' order**: as many
  marks as crossings (a train with a false trigger or a missed car is
  never put in order), every counter that read two of the cars typed them
  in the same order and together they order them all, the marks at least
  0.5 s apart (closer, a counter's hand swaps two cars too often for the
  order to prove anything), each counter's gap between two cars within
  0.5 s of the gap between their marks, and nothing the times made clear
  contradicted. Then the marks go to the cars in that order;
- anything else stays untimed: the pass keeps the counters' time, and the
  mark is left over ("ambígua" in the statistics, no flag).

**The per-upload count gives the same pass times as the whole race.** A
mark only depends on the readings and marks a few seconds around it. So the
recount of an upload loads, for each car it recounts, the marks within
2.5 s of each of its crossings and every entry a few seconds around those
marks, round after round until no train is missing a piece
(`SensorTrains::rangesToLoad`); and it recounts, besides the cars of the
upload, the cars whose marks the upload moved: for an upload that only
added entries, the marks are matched with and without them and the cars
whose mark changed are recounted (nearly always none); for a void or a fix,
every car of a train near the moment (`SensorTrains::carsAround`). A
sensor's own upload recounts the cars of the trains around its marks, and
none around a "??" (a mark is no reading of any car). Tests check this
for every car of generated races and for single uploads and voids
(`SensorTrainTest`), and through the upload endpoint
(`IncrementalEqualsRebuildTest`, with a sensor). The cost is in
[Two paths](#two-paths).

**Exports.** The Passagens export has a last column `marca_cronometra_carro`
(for a mark, the car whose pass it times) and the Voltas export a last
column `tempo_do_sensor` ("sim" when the row's time is a sensor's mark);
the other columns keep their places.

**The start and the finish.** A pass is before the start, or after the
finish, by its pass time: with a sensor, the mark, which nearly always
comes before every counter's Enter. A car that crossed just before a start
recorded a moment late is before the start even when every counter typed
it after the start instant (`PassGroup::inicioMs()`). A pass whose mark is
before the finish and whose counters' entries are all after it lies on
both sides of the finish, as with a precise source among the entries
(flag `no_encerramento`); it counts before the finish, by the mark.

**Moving the start.** The question "N registros de M carros" counts the
counters' "??" (a crossing somebody saw) and not the sensor's marks (a mark
alone never counts, before the start or after).

Its statistics (review block and Fontes page): marks, how many time a pass,
how many were near passes but not safely paired (no flag: nothing is lost
but a precise time), how many had no pass anywhere near (noise), the passes
it did not mark while it was synced, and its offset from the counters.

A mark with no pass the counters recorded is usually noise. It raises
`numero_ilegivel` only when some car was due right then (read strictly:
nobody recorded that car within a matching window, and either it is less
than a lap overdue or its passes around are exactly one lap apart on both
sides), before the finish: then it is probably a crossing every counter
missed. It never counts until a reviewer says which car it was; then it is
that car's pass on the reviewer's word, timed by the sensor.

**Measured** (`UnreadPassSyntheticTest`, eight races at full size: 64 cars,
four hours, 230 s laps, the usual mistakes, "??" on 2 % of the crossings,
the sensor missing 2 % of the crossings and marking one crossing that did
not happen every minute):

| | three counters | two counters |
|---|---|---|
| passes timed by the sensor | 76.5 % (19 988 of 26 129) | 77.0 % (20 089 of 26 102) |
| of those, the right car | 99.70 % (60 wrong) | 99.72 % (56 wrong) |
| before the 1 s rule for a mark alone with a crossing | 19 993, 65 wrong | 20 091, 58 wrong |
| before trains were decided on their own | 69.7 %, 99.66 % right | 69.1 %, 99.71 % right |
| by the counters' order alone | +18 marks, all right | +26 marks, all right |

The test asserts at least 99.5 % right and 74 % timed. Most of the gain is
the trains decided one by one with each counter's delay taken out; the
counters' order adds little in these races, because a simulated counter
types two cars less than half a second apart in either order (its hand
is ±0.4 s), and cars further apart are already told apart by the times.
What stays untimed is mostly cars side by side (marks under 0.3 s apart)
and trains with a false trigger or a missed car. The wrong ones are a mark
of a false trigger next to a crossing whose own mark was missed, or a car
a few tenths of a second from the next. One case the times cannot tell:
two cars under a second apart, the sensor missing the first and a false
trigger right after the second, the marks' gap about the cars' gap. The
two marks then line up with the two crossings at a lag near zero as well
as the real lag lines one of them up, so both cars get the wrong mark,
late by the gap. Limiting the lag to its likely sign does not help (that
lag is near zero, not after the counters), and the race-wide habit of the
counters is not known to the per-car recount, which must give the whole
race's answer. About 2 marks a race were flagged
with three counters, 6 with two, all of them false triggers; none was a
crossing nobody saw, because the simulated counters never all miss one.

### On the review screen

What a "??" looks like on the timeline, in its own lane: **purple, dashed,
"??"** while nobody gave it a car (not the grey of a void or of an entry
out of use: it waits for a person); **the car with a purple "auto"**
when the engine gave it one (the tooltip says why: "atribuído
automaticamente ao carro 7: Contagem 1 e 2 leram o carro 7 a 0,4 s",
from the `numero_atribuido` flag); the car with **"C"** when its own
counter fixed it on the phone, **"R"** when a reviewer did. A voided one
is struck through like any void.

*The car filter.* An assigned "??" belongs to its car, in its whole race
("Só o carro 7") and in focus among the others. A "??" nobody gave a car
is never dimmed when another car is in focus: it may be that car. A car's
whole race also brings in the open "??" whose flag names it among its
candidates (`TimelineWindow::forCar`, from the stored state's flags, like
the confirmed short laps), drawn as "??" at their moment: the one beside a
lone entry of the car is often its missing witness. Every car near some
"??" would have been too loose a rule (a pack of five, and every "??" in
every car's race); one the engine ruled out says nothing about the car.

*The flag* (`numero_ilegivel`) says when, which source, and the cars it
may have been as big buttons, most likely first, keys 1 to 6:
"Carro 7 — Contagem 1 e 2 leram o carro 7 a 0,4 s" (who read it, from the
timeline on screen), "Carro 12 — esperado por volta deste horário",
"Carro 3 — ficaria sem esta volta". Under each, what it does: "junta-se à
passagem do carro 7, que já conta" (nothing is asked), "fica como registro
solitário do carro 12: sozinho, ainda não conta", or, marked "(+1 volta)"
and asked first with the short-lap question of K, "com este registro, dois
contadores concordam" (the only other reading of that car was a lone one).
"Outro carro" takes any number; V voids it ("não passou carro"). Each is a
`reatribuir` of the entry, the decision "Trocar" on a chip stores, so it
shows in the history and undoes from there. The timeline zooms to the "??"
and outlines the entries of every candidate car, each with its button's
number. A on this flag presses the suggested car's button, with the same
question when it asks one. The doubt sentence names the likeliest car
only, as a condition: "Se esta passagem foi do carro 7, ele pode estar com uma volta a menos";
in the car table the flag counts as an open flag of each car it names,
red only on the likeliest one's row. On a sensor's mark the words are a
mark's ("Marca do sensor sem passagem", "Anular a marca (não foi
passagem)"), and giving it a car always adds a pass on the reviewer's word
(+1 volta, asked). The same buttons are in the box of a "??" chip picked
on the timeline.

*What the system did* (`numero_atribuido`) is listed under "Resolvidos
automaticamente" and in the history as an **automático** line ("Sistema:
atribuiu o ?? #123 de Contagem 2 ao carro 7", type "Número atribuído
(sistema)"), with "Ver". Nothing is stored for it, so no history row
changes and nothing new travels with the history difference. **Não é o
carro 7…**, on that flag or on the chip, opens the other cars the other
sources read near it (not one the "??"'s own counter typed right there:
given that car, its two entries would be a double tap, and the one it
typed would be ignored), "Outro carro" and "Anular (não passou carro)"; a
reviewer's decision wins over the engine's, and the line then gives way to
the reviewer's ("Atribuiu o ?? #123 (Contagem 2) ao carro 21"). A counter's
fix on the phone is listed the same way, "Quem: contador (no aparelho)".

*The sensor* has a lane of its own: a thin tick at each mark's exact moment,
green when it times a pass ("marca do sensor: tempo desta passagem"),
faint when it has none ("marca sem passagem"). The counters' chips of a pass
it times carry a small green "S" ("horário desta passagem: a marca do
sensor (preciso)"); picking the mark outlines them, and picking one of them
outlines the mark. A mark that times a pass is not asked "which car?":
the counters said it, and under car focus it goes with its pass's car
(`grupo.carro`). A mark offers only "Anular" and a car: confirming it or
discarding "its" pass would act on the counters' pass.

*The sources.* A counter's block adds "ilegíveis: 3 (1 atribuído)" when it
recorded any (and the status bar chip's tooltip says it, the chip's text
keeping its shape). A sensor that counts shows what it did with its marks:
marcas, atribuídas (they time a pass), ambíguas, sem passagem, passagens
sem marca and desvio (median offset from the counters, and 90 %; each
label explained in its tooltip). An observer keeps its comparison, with
a camera's "??" as "ilegíveis: 4 (3 numa passagem oficial)" and a sensor
on trial in a sensor's words (marcas numa passagem oficial, passagens oficiais sem
marca, marcas sem passagem).

---

# Part 2 — How it works

## Data

All in MySQL, tables `contagem_*` (see `baja-php/schema.xml`). Times are
integer milliseconds since the Unix epoch, UTC.

| Table | Content | Written by |
|---|---|---|
| `contagem_fonte` | Sources | Fontes page |
| `contagem_fonte_usuario` | Which user may count on which source | Usuários page. The pit log has no rows here: its code is in `user.permissions` |
| `contagem_dispositivo` | Devices and their token hashes, last contact, "synced through", closed | Device registration, sync |
| `contagem_passagem` | Raw entries. **Append-only**. `equipe_numero` is NULL for a pass recorded without a number ("??", a timing sensor's mark) | Sync |
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
  (`CountCarsTest` checks it for many generated races). The one exception
  is a "??": which car it was comes from what the other sources read
  around it. So where a "??" lies near the cars of an upload, the quick
  recount also loads the entries around it and recounts the cars whose
  count it may have moved (`EngineInputLoader::carsNearUnread`): for an
  upload that only added entries, the "??" near them are given their cars
  with and without the new entries, and only the cars whose "??" changed
  are recounted (nearly always none but the upload's own); for a void or
  a fix, every car such a "??" may be, or was, given.
  `IncrementalEqualsRebuildTest` checks that with "??" and fixes too. The
  other exception is a timing sensor's mark: it is the pass time, and which
  pass it times depends on the cars a second or two around it, so the
  recount also loads the marks and readings around its cars' crossings and
  recounts the cars whose marks the change moved
  ([The timing sensor](#the-timing-sensor)). With neither "??" nor sensor
  an upload costs what it did (one cheap query more than before stage 1);
  with a "??" and no sensor, no query more for the sensor.
- **What an upload costs** (64 cars, three counters, three hours into a
  four-hour race, one entry per upload, median; measured on the test
  stack):

  | | a counter's upload | a sensor's upload |
  |---|---|---|
  | no "??", no sensor | 32 / 126, lock 26, 27 / 223 queries | |
  | "??" on 2 % of crossings, no sensor | 88 / 194, lock 80, 47 / 243 queries | |
  | sensor, no "??" | 63 / 181, lock 55, 48 / 244 queries | 59 / 174, lock 49, 42 / 242 queries |
  | "??" and sensor | 117 / 253 (159 / 362 before the "??" path recounted only the cars whose "??" moved), lock 145 before | 89 / 259, lock 72, 40 / 237 queries |

  A counter's own "??" upload with both: 29 ms p50 (130 before). Measured
  in the process for that last row (SyncHandler::handle, 215 uploads): a
  counter's upload 100 ms p50 / 211 p90, against 163 / 268 before.

  The p95 is the points of every car (about 200 queries), recalculated
  whenever an upload changes a published value, as before. With a sensor
  the extra time is the trains' entries (a few seconds around each crossing
  of each car recounted) and working out, round by round, what is still
  missing. A sensor's own upload recounts the cars of the trains around its
  mark (1.2 to 1.5 cars, 3 000 to 4 500 entries loaded with both).
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
at 300 ms. With a timing sensor (64 cars, four hours, "??" on 2 % of the
crossings) a full run takes about 85 ms, against about 30 ms for the same
race without the sensor and 65 ms when trains were not yet decided one by
one: matching some 3 500 marks, train by train, is most of it.

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

- **Tables.** `baja-infra/mysql/migrations/2026-contagem.sql` creates the
  tables and changes no existing row. Read its header about character sets.
- **Columns added later.** A database that already has the contagem_*
  tables (the dev VPS, after 26SE) gets the newer columns from the same
  file's "Columns added later" block, safe to run twice:
  `contagem_decisao.passagem_alvo_id` (the "usar_tempo" decision),
  `contagem_fonte.papel`, `papel_desde_ms`, `periodos_fora`,
  `imagem_base_url` and `contagem_passagem.imagem_ref`, `confianca`; and
  `contagem_passagem.equipe_numero` becomes nullable (a "??" has no car).
  Every recount reads them, so run the file again before deploying the code
  that uses them.
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
| 1 | `DecisionApplier` | Drops voided entries, moves reassigned ones, drops entries of excluded sources, adds reviewer passes, cancels undone decisions, notes the times a reviewer chose (`usar_tempo`); sets aside the "??" nobody gave a car and the sensor's marks |
| 1b | `UnreadAssigner` | Gives a "??" the car the other sources read around it, where that is clear and decides no count (see [Passagens sem número](#passagens-sem-número--e-sensor-de-tempo)) |
| 2 | `DuplicateFilter` | Voids double taps and handover duplicates; flags other repeats |
| 3 | `PassMatcher`, `PassTimeFuser`, `UnreadAssigner::matchMarks` | Groups entries per car by time (never by typing order); the pass time of each group; a timing sensor's marks matched with the passes they time, train by train (`SensorTrains`), each mark then the time of its pass |
| 4 | `PublishRule`, `PassRoles`, `LapBuilder` | Which groups are official passes; leaves out the start crossing and late finishers; numbers pass zero and the laps |
| — | *(up to here is `LapCounter`: all the fast path needs)* | |
| 5 | `PassTimeFuser::removeHabits`, `PaceModel` | Lap durations with each counter's habit removed; expected lap per car; the field pace now and once a minute since the start (`ritmo`, `ritmoHistorico`) |
| 6 | `FlagDetector`, `SwapFinder`, `LateFirstPass`, `UnreadFlags` | The flags and their suggestions |
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

**A time a reviewer chose** ("usar_tempo") replaces the median: the pass
is timed by that entry alone. With a crossing typed late
(`registro_tardio`), the late entry also joins the chosen entry's pass, as a
witness: it does not time it, its counter is not charged a miss, and the
pass teaches nobody's habit. The same decision on a held pass whose
entries are too far apart picks one of them instead of their midpoint.
The pass is precisely timed only when the chosen entry is a `precisa`
source's own.

**A timing sensor's mark** matched to the pass is its time, in the fast
path and in a full run alike: the pass time and the habit-corrected time
are the mark, the pass counts as precisely timed for best lap, and each
counter's habit is measured against it. Which pass a mark times depends on
the cars a second or two around it, so the fast path loads those too (see
[The timing sensor](#the-timing-sensor)).

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
an open flag, it does not overlap a pit stay or a red flag, it is not
impossibly short, and it is not longer than `fator_parado` expected laps
(the car stood still during it, somewhere nobody logged; at 26SE such a
72-minute lap was a car's only well-timed one and became its `BEST`). With `passagem_inicial = largada`, lap 1 is measured from
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
  "fixes":      [{"uuid": "…", "car": 17}],
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
`desconhecido`; `ts_device_ms` and `anchor` are kept for audit. `"car": null`
is a pass recorded without a number ("??"); an entry with no `car` at all
is not stored. A timing sensor's entries are always stored with no car,
whatever they say. `now_ms` is
the page's idea of the server's time right now, or null when it has no
anchor: the device's "synced through" mark moves to `min(now_ms, server
time)` only when `queue_empty` is true and `now_ms` is not null, and never
backwards. `pit_events[].tipo` is `box_entrada`, `box_saida`,
`abastecimento_entrada` or `abastecimento_saida`.

`fixes: [{"uuid", "car"}]` gives a counter's own "??" (an entry sent with
`"car": null`) the number typed afterwards. The page leaves the field out
when it has none, sends a correction only once its entry is in
`stored_uuids`, and sends it again until the answer names it in
`fixed_uuids` (stored) or `fixes_recusados` (never stored; shown as
"correção recusada" on the row). An answer with `fixes_recusados` and no
`fixed_uuids` counts every other correction it was sent as stored. Like a
void, it falls under the safety net below (an answer with neither list).

The response lists the uuids now stored, the server time (stamped when the
request arrived), the race state, the classified cars (`valid_cars`) and the
present but unclassified ones, the cars inside each area (for pit-log
devices), and whether fueling and simulation are on. Sending the same
entries again changes nothing, and never changes a stored time.

| Answer | Meaning for the client |
|---|---|
| 200 | Stored. Forget the uuids listed in `stored_uuids` and `voided_uuids` |
| 200 with `refused: [{uuid, motivo}]` | Those will never be stored (today: simulated records on a prova that does not allow simulation). Mark them "recusado pelo servidor" and move on; they stay on the device and in its export |
| 200 with `fixed_uuids` and `fixes_recusados` | Forget the fixes listed in `fixed_uuids`. Those also in `fixes_recusados` were not stored and never will be: the entry was typed with a number, is voided, was reassigned by a reviewer (whose word stands), belongs to a timing sensor, the car is no number, or the entry travelled in the same request and was itself refused. A fix whose entry has not reached the server is in neither list: send it again after the entry. A fix of an entry the page marked "recusado pelo servidor" in an earlier answer will never be acknowledged: drop it |
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
never counted. An external system may add `"imagem"` and `"confianca"` to
each entry (see [Visão computacional](#visão-computacional)); from any
other mode they are ignored. A fix (`fixes`) is a counter saying which car
one of their own "??" was: stored as a `reatribuir` with the counter as its
origin; sending again the car of the counter's newest fix stores nothing,
a fix to any other car (a car named in an older fix included) is stored and
the newest counts. At most 500 entries (and 500 voids, 500 fixes, 500 stop events) of one
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

The decision history travels as a difference. With each poll the screen
also sends the id of the newest decision it holds and the history's epoch
(`historico_desde`, `historico_epoca`); a changed state then carries in
`historico` only the decisions after that one, whole. Decisions are never
edited and their ids grow, but three fields of an older line are worked
out from other decisions: `ativa` and `pode_desfazer` (an undo arrived)
and `carro` (a reassignment, or the undo of one, moved the entry). The
answer lists, in `historico_alteradas` as `[id, ativa, pode_desfazer,
carro]`, every older line where these are not what they are for a decision
nothing touched (in force, undoable unless it is an undo, the car typed);
the screen sets every older line back to that and applies the list, so a
line that went back is right without being listed. Source names come in
`historico_fontes` (a counter can be renamed), and `historico_quantas` and
`historico_mais_antiga_id` let the screen check the merge and drop what
fell off past 1000. The server derives all of this rather than leaving it
to the screen: the screen never has to know the rules of undo and
reassignment, and a line that a stale snapshot got wrong is put right by
the next one. The decisions in force and the system's own resolutions are
sent whole as before. The epoch is the newest row "Zerar corrida" archived
for the prova, so after a reset the server sends the whole history (the
new race's ids may even repeat the old ones); it does the same when the
screen names a decision it does not have or one newer than any. The
screen asks for the whole state again whenever a difference does not fit
(another epoch, the decision it started after is missing, the count or
the order is off). With the 300 decisions of 99PF a changed state went
from 283 KB to 141 KB, the history's part from 144 KB to 1.3 KB with no
new decision and 3.7 KB with five. Most of it is the older rows other
decisions changed (60 there), a list that grows with the race's
reassignments and undos.
Decisions are stored under the count lock, so their ids are committed in
order and a snapshot never sees a decision without the older ones.

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
the duration of the last completed stay. Once the race has a start only race
time counts: a stay that ended before the start is not a stop, and one under
way at the start counts from the start (`desde_ms` is then the start; the
review table says "desde antes da largada"). And `ultimas_paradas`: the last ten
entries and exits of both areas across the field, newest first, each
`{area, tipo, carro, ts_ms, inferida, presumida}`. `inferida` is an exit a
reviewer marked; `presumida` is an exit nobody logged, which the count
assumed (it is not in the log; if the gate logs that exit later, the logged
one takes its place in the list). An unpaired event is listed there but is
not a stay. The same numbers per car are in the CSV export "paradas" on Controle
da corrida.

And the pace (see [Car status](#car-status)). Per car, `volta_esperada_ms`:
what the pace model expects of it, null while the field pace is unknown and
whenever the race is not running (before the start, red flag, after the
finish). And `ritmo`, `{volta_mediana_ms, voltas_na_janela, janela_s}`: the
field pace (null under eight laps), how many laps of the window it is taken
from, and the window. After the finish it stays as it was at the finish.
Both are as of `calculado_em_ms`, like the laps.

The pace minute by minute is a separate answer, so the feed every overlay
polls stays small: `contagem_feed.php?evt=26BR&p=END&ritmo=historico` gives
`{evento_id, prova_id, gerado_em_ms, calculado_em_ms, ritmo, ritmo_historico}`,
`ritmo_historico` being `[{ts_ms, volta_mediana_ms, voltas_na_janela}, …]`
once a minute from the start (the last point at the moment of the
recalculation, or at the finish). It is computed by the full run, stored in
the snapshot and cached like the feed; the Cloudflare rule of the
[deploy notes](#deploy-notes) already
covers it, because the query string is part of the cache key. The pace is
left out of the snapshot's token: it moves with the clock and no review
screen shows it.

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
with known truth and configurable human error (and, when asked, "??" and a
timing sensor: `unreadRate`, `timingSensor`, `sensorMissRate`,
`sensorFalseTriggersPerMinute`, drawn from a random stream of their own so
that a race without them is the race the seed always gave). No real race data is in the
repository.

The database suite builds a made-up event (`00NE`) for each test and removes
it afterwards; it takes a database-wide lock, so two runs at once queue
instead of trampling each other. Never run it against production.

## Choices worth knowing about

- **The pass time is the plain median (or a precise source's time, or the
  timing sensor's mark); habits are removed only for lap durations.** With
  habits in the pass time, the time of a pass would depend on the whole
  race's entries and on late uploads, and could not be computed by the
  per-car fast path. A sensor's mark depends only on what lies a few
  seconds around it, which the fast path loads.
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
- **A "??" never decides a count on its own.** It is applied by itself only
  where two other sources read the crossing; with one reading a person
  confirms it. A timing sensor never counts a pass at all; it times passes,
  and the quick per-car count loads the marks and readings around its cars
  to time them exactly as the whole race does.
- **A pass typed for an unclassified car is stored and flagged, never
  rejected.** A phone's car list can be stale, and storing everything is
  what answers a protest.
- **The simulation never changes settings**, and simulated records are
  refused unless the prova allows them.
- **The count page's flash, status bar and banner have a fixed height**
  (`contar-destaque.js`, `status-bar.js`, `.destaque` and `.barra-linha` in
  `contagem.css`). The texts that go in them are short on purpose, and their
  long form lives in the recent list, the title attribute and the screen
  reader text. A change there must keep every state at the same height:
  the placeholder, a clean pass, a repeat, two warnings, "NÃO REGISTRADO", a
  long team name, an undo, a held count, a banner. This was checked in
  headless Chromium at 360 px wide; jsdom cannot measure layout. The "??"
  states (its flash, the correction banner over the box, a correction, its
  end) were checked the same way. The banner's two lines are cut each on
  its own and the instruction comes first, so a long time ("14:03:07
  (relógio do aparelho)") can only cut the time.
- **A correction of a "??" on the phone is one car, then over.** Taking the
  next car for the correction is what makes it quick, and also what makes
  it dangerous: a counter who forgot would turn a crossing into a
  correction and lose it. Hence the 20 s limit, the blue banner over the
  box and frame round the keys, and "Passou um carro", which records a "??"
  at the tap rather than merely leaving the mode, so that the crossing
  keeps its precise time whatever the counter does next.
