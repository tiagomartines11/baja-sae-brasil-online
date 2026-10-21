# Timers

Countdown sequences for timed sessions (design presentations and the like):
a list of steps, each with a duration and optional sounds, run from a phone
or laptop and shown identically on every device that has the timer open.

## Pages (juiz)

- `admin_timers.php`, `timer_admin.php` — create and edit timers for the
  current event. Same backup scheme as provas and premiações.
- `timer.php` — run a timer. Never edits it. `timer.php?k=<chave>` opens a
  timer with no login; logged-in users get a list of the timers they may use.
  Opened with the read-only key it shows the run and has no controls.
- `timer_action.php` — JSON endpoint the usage page polls and posts to.

## Permissions

| Code | Grants |
| --- | --- |
| `<evento>_TIMER_ADMIN` | configure and use every timer of the event |
| `<evento>_TIMER_<id>` | use that one timer |
| access key | use that one timer, no login |
| read-only key | watch that one timer, no login |

`admin` implies all of them. Both keys are shown on the timer's config page
and each can be regenerated there, which cuts off whoever had the old one.
`timer_action.php` refuses actions posted with the read-only key.

## How a run is stored

`timer.state` holds only what was last done: `status` (`running`, `paused`,
`stopped`), the `step` it applies to, `started_at` (when that step began, in
server milliseconds) or `remaining_ms` (when paused), and `at`, the time of
the action. Nothing ticks on the server. Each device works out the current
step and the time left from `started_at` and the step durations, so they all
agree, and a sequence keeps advancing with nobody connected.

## Without internet

The usage page is built to survive the venue network dropping:

- The countdown, step changes and sounds run on the device. Sounds are
  downloaded and decoded when the page opens.
- Actions taken offline are applied locally at once, kept in `localStorage`
  and posted when the connection returns. Each carries the time it happened;
  the server logs all of them and keeps the most recent as the state.
- A service worker (`timer_sw.js`) caches the page and the sounds, so a timer
  that was opened once can be reloaded offline. Service workers need HTTPS,
  so this part does nothing on `http://juiz.baja.local`.

What it cannot do: two devices that are both offline do not see each other,
and the one that acted last wins when they reconnect.

## Sounds

Any `wav`, `mp3`, `ogg`, `m4a` or `webm` file in `baja-php/juiz/sons/` shows
up in the config page's sound lists.

The config page can also make new ones from text ("Novo som"). The sentence
is sent to the `tts` container (`baja-infra/tts`, [Piper](https://github.com/OHF-Voice/piper1-gpl)
with a pt-BR voice baked into the image), which speaks it locally, and the
resulting wav is saved to `juiz/sons/gerados/`. That folder is not in git:
on a server where the code tree is replaced on deploy it needs to be a
persistent mount, or the generated sounds are lost. The voice is chosen at
build time with the `TTS_VOICE` build argument. With `TTS_URL` unset in
baja-app the option is hidden and everything else works as before.

## Deploying to an existing database

The table comes from the Propel migration
`baja-php/generated-migrations/PropelMigration_1790819962.php`, applied with
the others by `./propel migration:migrate`.

It creates the table as `latin1`. The charset has to match the `evento`
table's, or MySQL rejects the foreign key (error 3780), and the databases
that came from the production dump are `latin1`. On any other database,
check with `SHOW CREATE TABLE evento` before migrating.

The migration is `CREATE TABLE IF NOT EXISTS`, so it leaves an existing
table alone. That matters for a database that already had the table from
before the read-only key (staging): it has no `view_key`, and the migration
will not add it. Only there, add the column and a key for each existing
timer by hand:

```sql
ALTER TABLE `timer` ADD COLUMN `view_key` char(6) NULL AFTER `access_key`;
SET @a = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
UPDATE `timer` SET `view_key` = CONCAT(
  SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1), SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1),
  SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1), SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1),
  SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1), SUBSTRING(@a, FLOOR(1 + RAND() * 31), 1)
);
ALTER TABLE `timer` MODIFY `view_key` char(6) NOT NULL,
  ADD UNIQUE KEY `timer_view_key_UNIQUE` (`view_key`);
```

If the last statement fails on a duplicate, run the `UPDATE` again. A key
equal to some timer's access key would also be a problem, since `?k=` checks
the access key first; with a few timers that is vanishingly unlikely, but
`Gerar Nova Chave de Leitura` fixes it if it ever happens.
