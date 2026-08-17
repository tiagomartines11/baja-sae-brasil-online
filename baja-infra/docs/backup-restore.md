# Database backups and restore

Written for whoever is on call, not for whoever wrote it. If you are
reading this at 2am during an event, start at
[Something is broken right now](#something-is-broken-right-now).

---

## Something is broken right now

**A database is gone or corrupted.** → [Restore a database](#restore-a-database)

**Uptime Kuma says the backup is down.** → [When Kuma alerts](#when-kuma-alerts)

**You are about to score a session and want a snapshot first.**

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
docker compose --profile tools run --rm backup
```

That is safe to run at any time. It takes a consistent snapshot without
locking writers, so scoring can continue while it runs. Do it before and
after every scoring session — the nightly run gives a 24-hour RPO, which
is the wrong answer on results day.

---

## What runs, and when

| Job | When | Where | Alerts via |
|---|---|---|---|
| Backup | 04:00 America/Sao_Paulo, daily | host cron on the VPS | `KUMA_PUSH_URL_BACKUP` |
| Verify | 04:00 Tuesdays | host cron on the VPS | `KUMA_PUSH_URL_VERIFY` |
| Prune | daily | Google Apps Script | `KUMA_PUSH_URL_PRUNE` |
| Second copy | daily | cron on TrueNAS | external dead-man's switch |

Each run produces **three separate artifacts** — one per database — plus
**one manifest** covering all three:

```
daily/20260817T040000Z/
    baja_resultados.sql.gz.age   baja_resultados.sql.gz.age.sha256
    phpbb_baja.sql.gz.age        phpbb_baja.sql.gz.age.sha256
    phpbb_formula.sql.gz.age     phpbb_formula.sql.gz.age.sha256
    manifest.json
```

**The manifest is uploaded last, and its presence is what marks the run
complete.** A folder without one is a partial upload, not a backup.

---

## Keys, and who holds what

Three age recipients would be two too many, so there are exactly two, and
they exist for different reasons.

| Key | Private half lives | Used for |
|---|---|---|
| **escrow** | Offline, held by **João and Tiago**, separately | The human restore drill, and a real disaster |
| **verifier** | On the VPS, used by the weekly verify stack | The automated Tuesday check |

Every artifact is encrypted to **both**, always. That is not redundancy
for its own sake: the escrow private key never touches a server, so if it
were not already a recipient on every artifact, the day you need it you
could not add it retroactively.

Only public halves (`age1...`) ever appear in `.env`. `backup-db.sh`
refuses to start if it finds an `AGE-SECRET-KEY` there, or if the two
recipients are the same key.

> **If you hold the escrow key**: it is useless without this document, and
> this document is useless without it. Keep them findable by the other
> person. The twice-yearly drill exists to prove that both are still true.

Google identities, deliberately three:

| Identity | Role on the Shared Drive | Key lives |
|---|---|---|
| `baja-backup-writer` | **Contributor** — can upload, cannot trash | VPS |
| `baja-backup-reader` | **Viewer** | verify stack |
| dedicated role account (not a person) | **Manager** | owns the Apps Script pruner |

The writer being unable to trash is the property that stops a compromised
VPS from deleting the backups it just wrote. Re-verify it by hand after
any permissions change — see [acceptance tests](#acceptance-tests).

---

## Restore a database

This section assumes you have **only** two things: access to the Shared
Drive, and an age private key. No repo checkout, no VPS.

### 1. Get the artifact

With `rclone` configured for the Shared Drive:

```
rclone lsd gdrive:daily                       # list runs
rclone copy gdrive:daily/20260817T040000Z ./restore/ --progress
```

Or just download the folder from the Drive web UI.

### 2. Check it arrived intact

```
cd restore
sha256sum -c *.sha256
```

Every line must say `OK`. If one does not, the download is bad or the
artifact is — try again, and if it fails twice use the previous run.

Cross-check against the manifest, which records the same hashes:

```
jq -r '.artifacts | to_entries[] | "\(.value.sha256)  \(.value.file)"' manifest.json | sha256sum -c
```

### 3. Decrypt and decompress

```
age -d -i /path/to/escrow.key baja_resultados.sql.gz.age | gunzip -c > baja_resultados.sql
```

Confirm the dump is complete before you import it — this is the check that
catches truncation, which imports without error:

```
tail -1 baja_resultados.sql        # must be: -- Dump completed on ...
```

### 4. Import

Each artifact carries its own `CREATE DATABASE IF NOT EXISTS` and `USE`,
so restoring is one command and does not depend on you remembering the
database name:

```
mysql -u root -p < baja_resultados.sql
```

If you are restoring over an existing database, drop it first — an import
over live data merges rather than replaces, which is worse than either.

```
mysql -u root -p -e "DROP DATABASE IF EXISTS baja_resultados;"
```

Old dumps from before the containerised stack may need
`SET FOREIGN_KEY_CHECKS=0;` prepended; see `migrate.sh` for the historical
reason (a reversed-order primary key on `prova`).

### 5. Replay erasures — **do not skip this**

The artifact predates any LGPD erasure request made since it was taken.
Importing it resurrects data a subject asked to have deleted.

```
docker compose --profile tools run --rm --entrypoint erasure-log.sh backup \
    replay --database baja_resultados
```

That is a dry run. Read what it plans to do, then:

```
... replay --database baja_resultados --confirm
```

For the phpBB databases the entries are marked `manual` and the script
prints what to redo through the ACP rather than issuing SQL. That is
deliberate: deleting a phpBB user touches upwards of twenty tables and
several counters, and hand-written SQL leaves a forum that looks fine and
is quietly wrong.

### 6. Rebuild the phpBB search index — **only for `phpbb_baja` / `phpbb_formula`**

The search index is **excluded from the dumps** because it is regenerable
and large. A restored forum will have working posts and a search box that
returns nothing until you rebuild it:

**ACP → Maintenance → Search index → Create index.**

On a forum of our size this takes minutes. Do it before telling people the
forum is back.

### 7. Sanity-check before declaring victory

```
mysql -u root -p -e "SELECT COUNT(*) FROM baja_resultados.user;"
mysql -u root -p -e "SELECT COUNT(*) FROM baja_resultados.participantes;"
```

Compare against `manifest.json`:

```
jq '.artifacts.baja_resultados.table_counts' manifest.json
```

Then generate one certificate through the web UI. That exercises the query
the whole system exists for, and it is the check that catches an import
that succeeded and mangled every accented name.

---

## When Kuma alerts

The backup monitor alerts on **silence**. Nothing reports its own failure,
because a script that has to succeed at reporting failure can fail at that
too. So an alert means one of: the job failed, the job never started, or
the job could not reach Kuma.

Work through it in this order — cheapest first:

**1. Did it run at all?**

```
systemctl status cron
grep backup /var/log/syslog | tail -20
```

**2. What did it say?** Cron mails output, and the script logs each phase.
Re-run it by hand and watch:

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
docker compose --profile tools run --rm backup
```

**3. Read the first ERROR line, not the last.** Every failure mode has its
own message:

| Message contains | Means |
|---|---|
| `cannot connect to MySQL` | MySQL is down, or the backup user's password changed |
| `not visible to 'backup'` | The GRANT was lost — see [MySQL backup user](#mysql-backup-user) |
| `cannot list gdrive:` | Service-account key, Drive ID, or the Contributor role |
| `insufficient free disk` | The VPS is full. Nothing else will work either |
| `no '-- Dump completed' trailer` | The dump was truncated — MySQL died mid-run |
| `an age SECRET key is configured` | Someone pasted a private key into `.env`. Fix immediately |
| `exceeded BACKUP_DUMP_TIMEOUT` | The dump hung, usually a network partition |

**4. If the backup is genuinely broken and an event is imminent**, take a
manual dump to somewhere — anywhere — before debugging:

```
docker compose exec mysql mysqldump -u root -p --single-transaction \
    --routines --triggers --no-tablespaces --databases baja_resultados \
    > /root/emergency-$(date -u +%Y%m%dT%H%M%SZ).sql
```

An unencrypted dump on the VPS is not an acceptable steady state — it is
plaintext CPFs on a production host. Delete it once the real backup works.

---

## Weekly verification

An untested backup is a hypothesis. Every Tuesday at 04:00 the newest
`daily/` run is downloaded, decrypted, imported into a throwaway MySQL,
and compared against its own manifest.

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
./scripts/verify-run.sh
```

Cron:

```
0 4 * * 2 cd /srv/baja/baja-sae-brasil-online/baja-infra && ./scripts/verify-run.sh >> /var/log/baja-verify.log 2>&1
```

`verify-run.sh` runs on the host and owns the lifecycle; `verify-db.sh`
runs inside the verifier container and does the checking. The split is not
arbitrary: **asserting that a docker volume is really gone cannot be done
from inside a container**, and that assertion is the point.

### It shares a box with production

A verification job that takes prod down at 4am is strictly worse than no
verification. The guardrails, all of them deliberate:

- Its own compose project (`baja-verify`), its own bridge network, **no
  route to production MySQL**, no published ports.
- `mem_limit: 768m` plus `--innodb-buffer-pool-size=256M` and
  `--performance-schema=OFF`. The VPS has 4 GB and is already running
  production; a default MySQL alongside it invites the OOM killer, which
  does not pick the process you would have picked.
- **A different root password**, specifically so a misconfigured
  `mysql <` cannot reach production. `verify-db.sh` refuses to start if it
  matches `MYSQL_ROOT_PASSWORD`.
- Before importing anything it asserts the scratch server is **empty** and
  that its hostname is `baja-verify-mysql`. Restoring into the wrong
  instance is plausible and unrecoverable.
- A **read-only** Drive credential — `baja-backup-reader` (Viewer), plus
  rclone's `drive.readonly` scope.

Every run prints the peak memory it observed. Measure, do not assume:

```
[verify-run] peak memory observed across the verify stack: 222.9 MiB (mem_limit is 768 MiB)
```

### Hard versus soft

**Hard** — nothing arrived, a checksum mismatch, a failed import, a row
count that disagrees with the manifest, a mangled charset canary, or a
failed teardown. These suppress the Kuma heartbeat and the monitor alerts.

**Soft** — schema drift since the previous run, a large swing in total row
count, restore duration. These are logged under `for review` and do **not**
alert. Noisy checks train people to ignore the alert that matters, so
resist the urge to promote one.

Schema drift is soft on purpose: schema changes have been made directly in
MySQL before, so this is a free drift detector, and failing on it would
page someone for every legitimate migration.

### Reading the results

Results are **per database**, so a corrupt `phpbb_formula` never masks a
healthy `baja_resultados`:

```
[verify] ----- results -----
[verify]   baja_resultados: OK (restored in 1s)
[verify]   phpbb_baja: OK (restored in 2s)
[verify]   phpbb_formula: CHECKSUM MISMATCH
```

| Result | What to do |
|---|---|
| `OK (restored in Ns)` | Nothing. N is that database's measured RTO |
| `INCOMPLETE RUN, not a backup` | The manifest never uploaded. Check the backup job |
| `CHECKSUM MISMATCH` | The artifact is corrupt. Restore from the previous run |
| `TRUNCATED` | The dump died mid-write. The backup job is failing silently |
| `DECRYPT FAILED` | Wrong verifier key, or a damaged artifact |
| `row counts differ` | Data changed in transit, **or** the backup user lost SELECT |
| `CHARSET CANARY MISMATCH` | Accents did not survive. Do **not** restore from this |
| `certificate lookup is missing ...` | The restore imports but cannot produce a PDF |

The certificate lookup is the check that matters. Certificates are
generated on demand, so "the backup restored" means nothing until a
certificate can actually be produced from it. It picks its subject by a
deterministic rule — the oldest participant row on a certificate-issuing
event — and never by a hardcoded CPF, because this repository is public.

### "LEFTOVERS from a previous run were found"

The previous run did not tear itself down, which means a volume holding
**decrypted production data** sat on the production host until this run
destroyed it. The run continues, but this is a compliance incident, not a
warning to scroll past. Find out why the last run died — check
`/var/log/baja-verify.log` around its start time.

### Testing it without Google Drive

`VERIFY_REMOTE` and `VERIFY_LOCAL_DRIVE` point the verifier at a local
directory laid out like the Shared Drive, so the whole path can be
exercised without credentials. Both are already in `.env.example`. The
run logs a loud warning and never pings Kuma.

---

## Retention tiers, and reaching an old artifact

| | `daily/` | `monthly/` | `yearly/` |
|---|---|---|---|
| `baja_resultados` | 35 days | 24 months | indefinite |
| `phpbb_baja`, `phpbb_formula` | 35 days | 6 months | not written |

Deletion is to **Drive's trash**, never permanent, so "expires at 35 days"
really means "recoverable for about 65". Look in the Shared Drive's trash
before concluding something is gone.

Why these numbers, so nobody lengthens them without a reason: long
retention only ever recovers data that was deleted or modified and *not
noticed at the time*. Forum deletions are rare, and the Tuesday verifier
closes the detection gap to days. Extra copies of near-identical data buy
almost nothing, and because holding personal data past its purpose is an
Art. 15/16 liability rather than mere waste, shortening these is better
compliance as well as less to lose in a breach. `baja_resultados` keeps
the long tail because a scoring correction discovered years later is a
real scenario, and **certificates are generated on demand — the database
is the certificate archive.** A lost row is a credential a former student
can no longer verify.

`yearly/` holds `baja_resultados` alone. The certificate justification
does not extend to forum history.

To reach an old artifact:

```
rclone lsd gdrive:monthly
rclone lsd gdrive:yearly
rclone copy gdrive:yearly/20260101T040000Z ./restore/ --progress
```

A manifest in `monthly/` or `yearly/` describes the run **as created**, so
it may reference artifacts that tier no longer holds. That is by design,
not corruption.

---

## MySQL backup user

`mysql/init/02-create-users.sh` creates it, but **init scripts only run on
a database's first boot**. Production was initialised long ago, so the
user has to be created by hand there — once:

```sql
CREATE USER IF NOT EXISTS 'backup'@'%' IDENTIFIED BY '<MYSQL_BACKUP_PASSWORD from .env>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'%';
FLUSH PRIVILEGES;
```

Each grant earns its place against a `mysqldump` flag: `SELECT` reads the
rows, `SHOW VIEW` dumps view definitions, `TRIGGER` is what `--triggers`
needs, `EVENT` is what `--events` needs, and `LOCK TABLES` is historically
required and harmless alongside `--single-transaction` (which takes no
table locks).

There is no `INSERT`, `UPDATE`, `DELETE`, `CREATE` or `DROP`, so a
compromised backup container cannot alter production. `TRIGGER` is the one
grant that is not purely read-only — it also permits `CREATE`/`DROP
TRIGGER` — but it is the minimum MySQL offers for dumping triggers. If a
future change appears to need more, widen it deliberately and write down
why; do not reach for root.

Confirm it is read-only:

```
docker compose exec mysql mysql -u backup -p -e \
    "CREATE TABLE baja_resultados.should_fail (i INT);"
# expected: ERROR 1142 (42000): CREATE command denied
```

---

## Configuration

Everything lives in `baja-infra/.env`. See `.env.example` for the
annotated list. Two things about it are load-bearing:

- **Comments go on their own line, never trailing an assignment.** Compose's
  parser does not strip trailing inline comments reliably, and a trailing
  comment on `PHPBB_COOKIE_PREFIX` already broke the login shim once during
  the migration.
- `GDRIVE_SA_WRITER_JSON` is used **two ways**: in `.env` it is the HOST
  path to the service-account key, which compose bind-mounts; inside the
  container the same variable name holds the fixed read-only path the key
  appears at.

Host cron entry:

```
0 4 * * * cd /srv/baja/baja-sae-brasil-online/baja-infra && docker compose --profile tools run --rm backup >> /var/log/baja-backup.log 2>&1
```

---

## Erasure log

An append-only record of LGPD subject erasure requests, replayed after any
restore. It is the mitigation for the fact that backups make deletion less
final than it looks: historical artifacts are **not** rewritten, so the
restore procedure has to know what must not come back.

It **contains personal data** and therefore lives outside this repository —
the repo is public.

```
mkdir -p /srv/baja/erasure
chmod 700 /srv/baja/erasure
touch /srv/baja/erasure/erasure-log.jsonl
chmod 600 /srv/baja/erasure/erasure-log.jsonl
chattr +a /srv/baja/erasure/erasure-log.jsonl    # genuinely append-only
```

`chattr +a` means even root must clear the attribute before editing, which
turns a careless edit into a deliberate one. Each line also carries the
SHA-256 of the previous line, so `verify` detects an edit or a deletion in
the middle of the file. That is tamper *evidence*, not prevention.

Recording an erasure (**after** you have erased it in production):

```
docker compose --profile tools run --rm --entrypoint erasure-log.sh backup \
    record --request REQ-2026-014 \
           --database baja_resultados --table participantes --column cpf \
           --value 12345678901 \
           --note "Art. 18 request, ticket 014"
```

Replaying after a restore is [step 5](#5-replay-erasures--do-not-skip-this)
above. Check the chain periodically:

```
docker compose --profile tools run --rm --entrypoint erasure-log.sh backup verify
```

---

## Human restore drill

**Twice yearly, Tiago performs a restore from scratch using the escrow key
and this document alone.** Not João — bus factor is the entire point.

Automation verifies the artifact. It cannot verify the *recovery path*,
because an automated verifier encodes exactly the knowledge you are testing
whether a human has.

Rules:

- On a machine that is not the VPS.
- From the Shared Drive and the escrow key only. No repo checkout, no help
  from whoever built this.
- Follow [Restore a database](#restore-a-database) verbatim. Where it is
  wrong or unclear, that is the finding — fix the document, do not fix it
  in your head.
- Record: date, who, elapsed time, and everything the runbook got wrong.

| Date | Who | Duration | What the runbook got wrong |
|---|---|---|---|
| _(pending — first drill due after Phase 1 ships)_ | | | |

---

## Known limits

Read these before concluding something is broken.

**Row counts are taken just before each dump, not inside it.**
`--single-transaction` snapshots at the instant `mysqldump` starts, so the
counts in the manifest are captured immediately before that instant — the
closest a separate connection can get. A row committed in the sub-second
gap is in the artifact but not the count, which the verifier would report
as a mismatch. Each artifact records `counts_taken_utc` so a one-row
discrepancy on a manually-triggered run during an active event can be
recognised for what it is. At 04:00, when the automated run happens,
nothing is writing and the window is empty.

**The three artifacts are three snapshots seconds apart, not one atomic
snapshot.** `baja_resultados.user` rows correspond to phpBB user IDs, so a
registration landing mid-run can end up on one side of the boundary. The
symptom is the known unprovisioned-user path: the user is bounced to login
and an admin adds the row. This is noise next to the hours of data already
lost in any scenario where you are restoring at all. Do **not** "fix" it by
dumping all three in one transaction and splitting the file on
`-- Current Database:` markers; that puts dump-parsing on the critical path
of recovery.

**Excluded tables achieve no IP minimisation.** phpBB keeps `poster_ip` on
posts, `author_ip` on private messages and `user_ip` on users, and every
one of them is in these dumps. The exclusions are ephemeral session state
and the regenerable search index, nothing more. If IP retention needs
addressing under LGPD it belongs in the application and the live database —
pruning only at dump time satisfies nothing, because the rows stay in
production and the obligation is unmet.

**Audit tables are deliberately included.** `phpbb_log` carries the
moderation and administration trail. It is disputed-decision evidence, and
an attacker with ACP access can prune the live copy — which can leave the
backup as the only surviving record. Do not add it to the exclusion list to
save space.

**File and volume backups are out of scope.** phpBB `files/` and avatars
are not backed up by this. Attachments are not recoverable from these
artifacts.

---

## Acceptance tests

Re-run these after changing anything in `scripts/` or `docker/backup/`.

1. **A run produces the full set.**
   `docker compose --profile tools run --rm backup`, then confirm
   `daily/<run_id>/` holds three artifacts, three `.sha256` sidecars and a
   `manifest.json`.

2. **The writer cannot trash a file.** As `baja-backup-writer`:
   `rclone delete gdrive:daily/<run_id>/phpbb_formula.sql.gz.age` — this
   **must fail**. If it succeeds, the service account has the wrong role
   and the Contributor-cannot-trash property is gone.

3. **A run that dies partway leaves nothing behind.** Kill MySQL (or cut
   the backup container off the compose network) mid-run: non-zero exit,
   no folder uploaded, no Kuma ping, no plaintext left in the work
   directory.

4. **A run interrupted before the manifest fails verification.** Upload two
   artifacts and no manifest; the verify stack must call it an incomplete
   run, not a pass.

5. **Verification fails when it should.** Against a good run it passes;
   against each of a stale run, a corrupted byte, a truncated dump, a
   manually altered row count, and one artifact deleted from the folder, it
   fails.

6. **Per-database reporting.** A corrupt `phpbb_formula` must not mask a
   healthy `baja_resultados`.

7. **The verify stack leaves nothing.** No volume, no scratch database, no
   plaintext — including after a deliberately killed run.

8. **Verify peak memory stays inside its cap with production running.**
   Measure it; do not assume.

9. **The backup user cannot write.** See
   [MySQL backup user](#mysql-backup-user).

10. **Tier branches.** `BACKUP_RUN_ID=20260101T040000Z` must write
    `daily/`, `monthly/` and `yearly/`, with `yearly/` holding
    `baja_resultados` only. `BACKUP_RUN_ID=20260201T040000Z` must write
    `daily/` and `monthly/` but not `yearly/`.

11. **The pruner is correct in dry-run** across all four rules, including
    refusing to breach a floor, and pruning `phpbb_*` from a six-month-old
    monthly folder while leaving `baja_resultados`.

12. **One full manual restore on a different machine**, from the artifacts
    alone.

13. **`shellcheck` is clean**, and no secret, dump or real personal data
    appears anywhere in branch history.

### Running tests 1, 3 and 10 without Google Drive

`BACKUP_REMOTE` redirects uploads to any rclone destination and suppresses
the Kuma heartbeat, so a test run cannot mark the monitor healthy:

```
BACKUP_REMOTE=":local:/tmp/faked-drive/" \
BACKUP_RUN_ID=20260101T040000Z \
    docker compose --profile tools run --rm backup
```

It logs a loud warning on every such run. Leave `BACKUP_REMOTE` empty in
production.
