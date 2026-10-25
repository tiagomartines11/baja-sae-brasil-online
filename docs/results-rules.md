# Results rules

How a resultado's table is worked out: what each column shows, how teams
are ranked, and which teams can win an award. The public results page
(`prova.php`), the judges' copy (`prova_juiz.php`), the premiação screens
and the results API all follow these same rules. Before BAJAAPP-61 the
results pages and the premiação each had their own, slightly different,
rules; the "Before" lines say what changed.

The settings named here (`colunas`, `pos`, `pos_order`, `tiebreak`,
`highlight`…) are the resultado's JSON in the judge area, under
*Administrar resultados*. The judge help page `ajuda_resultado.php` explains
each setting; this page explains how they behave.

For developers: the code is `baja-php/src/Baja/Results/`, the tests that
show each rule are in `baja-php/tests/Unit/Results/` and
`baja-php/tests/Unit/Premiacao/`.

## Columns

### R1. A column can use earlier columns

Columns are worked out from left to right. Each column's value is then
available to the columns after it, by its header, written as a variable:

```json
{"colunas": [
  {"header": "Pontos", "val": "$P1 + $P2"},
  {"header": "Bonus",  "val": "$B"},
  {"header": "Total",  "val": "$Pontos + $Bonus"}
]}
```

Only headers that are valid variable names work this way (`Pontos`,
`Total`, `Segurança`); `"Pontos Finais"` has a space and can't be used.
A column can't use a column that comes after it. The `tiebreak` is worked
out after all columns, so it can use any of them. A `Pos` column has no
value yet when the other columns are worked out (positions come last), so
`$Pos` is empty.

*Before:* this worked on the results pages but not in the premiação, where
`$Pontos` was only an input variable of that name. An award could then use
a different number from the one on the results page.

### R6. `highlight` and `tiebreak`: empty means none

For a column with a list of formulas, `highlight` is a formula: lines equal
to its value are shown in **bold** (the attempt that counted, say).

- No `highlight`, or `"highlight": ""`: nothing is bold.
- Any other value is a formula, `"0"` included (`"0"` bolds the lines that
  are 0).
- A highlight formula that gives nothing (a typo, a missing variable) bolds
  nothing.

`tiebreak` follows the same rule: missing or `""` means no tiebreak;
`"0"` is a formula.

*Before:* on the results pages, a list column without a highlight showed
empty and 0 lines in bold (an empty `<b></b>`); the premiação did not.

### R7. Team variables

Every formula can use `$NUM` (car number), `$EQUIPE`, `$ESCOLA`,
`$ESTADO`, `$DESCLASSIFICADO` and `$TEAM_ID`.

*Before:* the results pages had `$DESCLASSIFICADO` but not `$TEAM_ID`; the
premiação the other way round.

### R8. Runs given as an object

A prova with several runs stores them as `"entries": [run, run, …]`. If
`entries` is a JSON object instead (`{"1": run, "2": run}`), its values are
the runs, in the order written.

*Before:* the premiação showed no rows for such a team.

### R9. Columns with no header

A column without `header` is still shown, with an empty title.

*Before:* the premiação dropped it.

## Reading numbers

### R2. How a cell is read as a number

The ranking and the tiebreak need a number from what a cell shows, which is
often HTML. To read it:

1. Struck-through content is dropped, with what is inside it: `<s>…</s>`,
   `<strike>…</strike>`, `<del>…</del>`. That is how a score that no longer
   counts is usually shown.
2. The rest of the HTML is removed.
3. The **first** number left is taken: an optional minus, digits, and an
   optional decimal part with `.` or `,`. A comma is a decimal point.

| Cell shows | Read as |
|---|---|
| `<b>812.4</b>` | 812.4 |
| `1,5 pts` | 1.5 |
| `<b>53.9%</b><br /> 14.37` | 53.9 (the first number) |
| `<b>0</b><br /> <s>13.87</s>` | 0 |
| `<s>12</s>` | no value |
| `DNF`, `SPOILERS`, empty | no value |
| `0` | 0 (0 is a value) |

*Before:* the results pages read only the digits at the very start, after
removing HTML, so `1,5 pts` was 1 and anything that did not start with a
number was 0. Struck-through content was not dropped. The premiação took
the first number anywhere, including struck-through ones.

## Ranking

A resultado ranks its teams when it has `pos`.

### R5. What `pos` and `pos_order` mean

- `pos` is the header of the column to rank by. If no column has that
  header, it can be the name of a formula variable (`"pos": "PONTOS"`).
  If neither exists (a typo), no team has a value, so nobody is ranked
  (R3).
- `pos_order`: `"desc"` (the default) ranks the highest value first, for
  points; `"asc"` ranks the lowest first, for times. Capital letters don't
  matter (`"ASC"` works).
- Without `pos`, or with `"pos": ""`, there is no ranking: every `Pos` cell
  shows "–".

*Before:* on the results pages `"pos_order": "ASC"` ranked the highest
first. With a `pos` that named nothing, everybody was shown 1st.

### R3. No value means no position

A team whose value can't be read as a number (R2) gets **no position**:

- its `Pos` cell shows "–" (no medal);
- it is listed after every ranked team, by car number;
- it never ties with anyone;
- it never wins an award.

*Before:* such teams were read as 0 (results pages), or ranked one after
the other by car number (premiação). For example, in the seed's 26BR
Relatório de Projeto the "Pontos" formula is broken (it joins text with
`+`, which PHP rejects; it should be `.`), so every cell is empty. The page
showed all 49 teams as 1st with a gold medal. It now shows "–" for all of
them, which makes the broken formula visible.
[`results-config-check.md`](results-config-check.md) lists every such
config and writes the fix for this kind.

### R4. Ties

Teams with equal numbers share a position, and the next one is skipped:
1, 2, 2, 4.

- With a `tiebreak`, it separates them. It is read as a number too (R2) and
  goes in the same direction as `pos_order` (higher first with `"desc"`).
  A team whose tiebreak has a value goes before one whose tiebreak has
  none.
- Teams still tied after the tiebreak keep the shared position, and are
  listed by car number.

Equal numbers tie whatever the text around them: `74.81` and
`74.81 Finalista` are a tie.

*Before:* the results pages compared the text, so `74.81` and
`74.81 Finalista` got different positions (22BR APR showed 16th and 17th for
the same score). The premiação compared the numbers but never let empty
cells tie.

### R10. Order of the rows

- The results page lists rows by the run's `ts` (when the prova records
  one), then by car number. The browser then sorts the table by whatever
  column the visitor clicks. This has not changed.
- The premiação lists them in position order.

## Awards

A premiação category picks teams from a ranked resultado, by `positions`
(`[1]` for the winner) or by `top_n`. Ranking and positions are exactly the
results page's (R2 to R5). A team with no position (R3) is never selected,
even when `top_n` asks for more teams than are ranked.

## Spoilers

### R11. Who sees past spoilers

During an event, the organisers can turn spoilers on: columns with a
`val_spoilers` show that instead of their value, until the event is
finished.

- The public results page and the API: spoilers apply to everybody.
- The judges' copy: users in the resultado's `authorized_users`, and
  developers, see the real values.
- The premiação never hides spoilers. It is only for the organisers.

The ranking uses what is shown, so during spoilers it ranks by the spoiler
value (known issue BAJAAPP-42). With a spoiler value such as `"SPOILERS"`
or `"?"`, which has no number, every team now shows "–" in the `Pos`
column (R3). Before, every team was shown 1st with a gold medal.

## Raw values (for the API)

Each cell also has a raw value, for programs:

- struck-through content and HTML are removed, as in R2;
- what remains is a number only when it is **exactly** a number (a comma
  decimal is fine): `12,5` gives 12.5, `12,5 pts` stays the text
  `12,5 pts`;
- empty gives nothing (null);
- a list column gives one raw value per line;
- a column with a `val_api` formula gives that formula's value instead,
  except while spoilers hide the column.
