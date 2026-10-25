# Backups and restore

Written for whoever is on call, not for whoever wrote it. If you are
reading this at 2am during an event, start at
[Something is broken right now](#something-is-broken-right-now).

What is backed up: the three databases (`baja_resultados`, `phpbb_baja`,
`phpbb_formula`), each forum's attachments and uploaded avatars, the timer
sounds generated on the server, the LGPD erasure log, and, once an event
is finished, a frozen copy of its published results. What is not, and
why, is in [What is not in the backups](#what-is-not-in-the-backups).

---

## Something is broken right now

**A database is gone or corrupted.** → [Restore a database](#restore-a-database)

**The whole server is gone.** → [Restore onto a fresh server](#restore-onto-a-fresh-server)

**Forum attachments or avatars are gone.** → [Restore files](#restore-files)

**Uptime Kuma says the backup is down.** → [When Kuma alerts](#when-kuma-alerts)

**You are about to score a session and want a snapshot first.**

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile tools run --rm backup
```

That is safe to run at any time. It takes a consistent snapshot without
locking writers, so scoring can continue while it runs. Do it before and
after every scoring session — the nightly run gives a 24-hour RPO, which
is the wrong answer on results day.

In production and on staging every `docker compose` command in this
document needs `-f docker-compose.yml -f docker-compose.prod.yml`: the
prod overlay is what mounts the forum uploads from `/srv/baja` into the
backup container. Without it the backup archives the empty mount points
instead. `scripts/backup-run.sh` adds it by itself whenever `ENV` is not
`dev`.

---

## What runs, and when

| Job | When | Where | Alerts via |
|---|---|---|---|
| Backup: databases, files, erasure log | 04:00 America/Sao_Paulo, daily | host cron → `scripts/backup-run.sh` | `KUMA_PUSH_URL_BACKUP` |
| Event archives (BAJAAPP-67) | right after the backup, same script | host cron → `scripts/backup-run.sh` | `KUMA_PUSH_URL_ARCHIVE` |
| Verify | 04:00 Tuesdays | host cron → `scripts/verify-run.sh` | `KUMA_PUSH_URL_VERIFY` |
| Prune | daily | Google Apps Script | `KUMA_PUSH_URL_PRUNE` |
| Secondary copy (optional) | whatever its operator chooses | outside the VPS, read-only account | a check that does not run on the same machine — see [Secondary copies](#secondary-copies) |

Each nightly run produces **one artifact per database, one per file set,
one for the erasure log**, a `.sha256` sidecar for each, and **one
manifest** covering all of them:

```
daily/20261025T070000Z/
    baja_resultados.sql.gz.age        baja_resultados.sql.gz.age.sha256
    phpbb_baja.sql.gz.age             phpbb_baja.sql.gz.age.sha256
    phpbb_formula.sql.gz.age          phpbb_formula.sql.gz.age.sha256
    phpbb_baja-files.tar.gz.age       phpbb_baja-files.tar.gz.age.sha256
    phpbb_formula-files.tar.gz.age    phpbb_formula-files.tar.gz.age.sha256
    baja-app-sons.tar.gz.age          baja-app-sons.tar.gz.age.sha256
    erasure-log.jsonl.age             erasure-log.jsonl.age.sha256
    manifest.json
```

| File set | What it holds | Where it lives in production |
|---|---|---|
| `phpbb_baja-files`, `phpbb_formula-files` | `files/` (attachments) and `images/avatars/upload/` (avatars) | `/srv/baja/phpbb-<forum>/files`, `/srv/baja/phpbb-<forum>/avatars` |
| `baja-app-sons` | `gerados/`, the timer sounds made by text to speech | `baja-php/juiz/sons/gerados/` in the checkout |

The run id is UTC (`T070000Z` is 04:00 in São Paulo).

**The manifest is uploaded last, and its presence is what marks the run
complete.** A folder without one is a partial upload, not a backup. The
same rule holds for each event archive under `events/`.

Host cron:

```
0 4 * * * /srv/baja/baja-sae-brasil-online/baja-infra/scripts/backup-run.sh >> /var/log/baja-backup.log 2>&1
0 4 * * 2 cd /srv/baja/baja-sae-brasil-online/baja-infra && ./scripts/verify-run.sh >> /var/log/baja-verify.log 2>&1
```

On Tuesdays both start at 04:00. That is fine: verify reads Drive, and
its freshness limit (26h) is met by Monday's run. Move verify to 05:00 if
you want it to check Tuesday's.

---

## Keys, and who holds what

Three age recipients would be two too many, so there are exactly two, and
they exist for different reasons.

| Key | Private half lives | Used for |
|---|---|---|
| **escrow** | Offline, held by **two maintainers, separately** | The human restore drill, and a real disaster |
| **verifier** | On the VPS, used by the weekly verify stack | The automated Tuesday check |

Every artifact — dumps, file sets, erasure log, event archives — is
encrypted to **both**, always, by one function (`backup_encrypt` in
`scripts/backup-lib.sh`). That is not redundancy for its own sake: the
escrow private key never touches a server, so if it were not already a
recipient on every artifact, the day you need it you could not add it
retroactively.

Only public halves (`age1...`) ever appear in `.env`. The backup refuses
to start if it finds an `AGE-SECRET-KEY` there, or if the two recipients
are the same key.

> **If you hold the escrow key**: it is useless without this document, and
> this document is useless without it. Keep them findable by the other
> holder. The twice-yearly drill exists to prove that both are still true.

Google identities, deliberately three:

| Identity | Role on the Shared Drive | Key lives |
|---|---|---|
| `baja-backup-writer` | **Contributor** — can upload, cannot trash | VPS |
| `baja-backup-reader` | **Viewer** | verify stack, and any [secondary copy](#secondary-copies) |
| dedicated role account (not a person) | **Manager** | owns the Apps Script pruner |

The writer being unable to trash is the property that stops a compromised
VPS from deleting the backups it just wrote. Re-verify it by hand after
any permissions change — see [acceptance tests](#acceptance-tests). It is
not the same as being unable to *damage* them: see
[Known limits](#known-limits).

---

## Secrets: kept by the maintainers, not by the backups

The backups hold data, never the secrets that run the stack. An artifact
that carried `.env` would carry the database passwords next to the data
they protect, and the escrow design would no longer matter. So none of
the following is in any artifact, and every one of them must be kept by
the maintainers, in the shared password manager entry for the production
server, **updated in the same sitting as any change on the server**:

| Secret | Why it cannot be lost |
|---|---|
| the whole production `baja-infra/.env` | every password and key below lives in it; restoring without it means re-creating each one |
| `STORAGE_ENCRYPTION_KEY` (in `.env`) | `Baja\Util\Encryption` cannot decrypt anything without it, and there is no key versioning. Nothing on `develop` encrypts with it yet, but the first feature that does makes losing it a data loss that no backup can repair |
| `CERT_BACKOFF_SECRET`, `STAFF_COOKIE_VALUE` (in `.env`) | rotatable, but rotating `STAFF_COOKIE_VALUE` also means editing the Cloudflare cache rule |
| the two Google service-account JSON keys (`GDRIVE_SA_WRITER_JSON`, `GDRIVE_SA_READER_JSON`) | can be re-issued from the Cloud console by a Shared Drive Manager; keep them so a restore does not wait for that |
| the verifier age **private** key (`AGE_IDENTITY_VERIFIER`) | without it the weekly verification cannot decrypt; a new pair means only new artifacts are checkable by it |
| the Cloudflare API token files (`~/.secrets/cloudflare-*.ini`, see `docs/server-migration.md`) | needed to re-issue the TLS certificates |

The **escrow** private key is not in that entry: it is held offline by
its two holders, apart from the server's secrets, so that one leaked
vault does not open every backup.

Things that are deliberately not kept at all, because a fresh server
makes them again: the Let's Encrypt certificates (`certbot`), the
Tailscale node key (`tailscale up`), the phpBB `config.php` files
(rendered from `.env` at boot).

---

## Restore a database

This section assumes you have **only** two things: access to the Shared
Drive, and an age private key. No repo checkout, no VPS.

### 1. Get the artifact

With `rclone` configured for the Shared Drive (the reader account is
enough):

```
rclone lsd gdrive:daily                       # list runs
rclone copy gdrive:daily/20261025T070000Z ./restore/ --progress
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
jq -r '(.artifacts, .file_sets // {}) | to_entries[] | "\(.value.sha256)  \(.value.file)"' manifest.json | sha256sum -c
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
database name. Into the stack's MySQL, from `baja-infra/`:

```
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD"' < baja_resultados.sql
```

**Drop the database first** if one exists — an import over existing data
merges rather than replaces, which is worse than either. On a fresh
server one always exists: the first boot of the MySQL container loads the
dev seed from `mysql/init/`.

```
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS baja_resultados"'
```

Old dumps from before the containerised stack may need
`SET FOREIGN_KEY_CHECKS=0;` prepended; see `migrate.sh` for the historical
reason (a reversed-order primary key on `prova`).

If the restored `baja_resultados` is older than the code you deploy, apply
the migrations it is missing (`./propel migration:status` lists them; the
command is in `docs/dev-workflow.md`).

### 5. Replay erasures — **do not skip this**

The artifact predates any LGPD erasure request made since it was taken.
Importing it resurrects data a subject asked to have deleted.

Replay the **newest** erasure log there is, never the one from the run you
restored: the log is append-only, so the newest copy holds every erasure
the older ones do, and the ones made since. That is the live log at
`/srv/baja/erasure/` if the server survived, or else `erasure-log.jsonl.age`
from the newest `daily/` run ([Restore the erasure log](#restore-the-erasure-log)).

```
docker compose --profile tools run --rm erasure-log replay --database baja_resultados
```

That is a dry run. Read what it plans to do, then, with credentials that
can write to the restored database:

```
docker compose --profile tools run --rm -e MYSQL_ADMIN_USER=root -e MYSQL_ADMIN_PASSWORD \
    erasure-log replay --database baja_resultados --confirm
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
forum is back. Purge the forum cache after any database restore:

```
docker compose exec -T phpbb-baja su-exec www-data php bin/phpbbcli.php cache:purge
```

### 7. Sanity-check before declaring victory

```
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT COUNT(*) FROM baja_resultados.participantes"'
```

Compare against `manifest.json`:

```
jq '.artifacts.baja_resultados.table_counts' manifest.json
```

Then generate one certificate through the web UI. That exercises the query
the whole system exists for, and it is the check that catches an import
that succeeded and mangled every accented name.

---

## Restore files

Each file set is a tar of paths relative to the forum's (or the sound
folder's) root, with numeric owners kept, so the forum's `www-data` (uid
82) owns the files again after restore.

```
mkdir -p restore/phpbb_baja-files
age -d -i /path/to/escrow.key phpbb_baja-files.tar.gz.age \
    | tar -xzf - -C restore/phpbb_baja-files --numeric-owner
ls restore/phpbb_baja-files      # files/  images/avatars/upload/
```

The archive's layout is the forum's own. In production the two folders are
bind mounts with other names, so copy each one to its place:

```
sudo rsync -a --numeric-ids restore/phpbb_baja-files/files/ /srv/baja/phpbb-baja/files/
sudo rsync -a --numeric-ids restore/phpbb_baja-files/images/avatars/upload/ /srv/baja/phpbb-baja/avatars/
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart phpbb-baja
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart nginx
```

Same for `phpbb_formula-files` into `/srv/baja/phpbb-formula/`. The
`restart nginx` is the one from the `docs/dev-workflow.md` FAQ: skip it
and one forum may serve the other's board. Restore the files and the
database **from the same run**: a forum's posts point at attachments by
the names in that run.

To check what you restored against the manifest without trusting your
eyes, `backup_tree_lines_from_dir` and `backup_tree_summary` in
`scripts/backup-lib.sh` compute the manifest's `file_count`, `total_bytes`
and `tree_sha256` for any folder; the weekly verification does exactly
that.

The timer sounds go back into the checkout:

```
age -d -i /path/to/escrow.key baja-app-sons.tar.gz.age \
    | tar -xzf - -C /srv/baja/baja-sae-brasil-online/baja-php/juiz/sons
```

(`gerados/` must stay writable by php-fpm; the baja-app entrypoint makes it
so on its next start.)

## Restore the erasure log

```
age -d -i /path/to/escrow.key erasure-log.jsonl.age > erasure-log.jsonl
sudo install -d -m 700 /srv/baja/erasure
sudo install -m 600 erasure-log.jsonl /srv/baja/erasure/erasure-log.jsonl
sudo chattr +a /srv/baja/erasure/erasure-log.jsonl
docker compose --profile tools run --rm erasure-log verify
```

Use the copy from the **newest** run. Then replay it (step 5 above).

## Restore onto a fresh server

The order matters; each step needs the previous one.

1. **The server and the stack**, per `docs/server-migration.md`
   ("Configuração Sistema" and "Deploy do GIT"), with the production
   `.env` taken from the maintainers' password manager entry
   ([Secrets](#secrets-kept-by-the-maintainers-not-by-the-backups)), not
   from `.env.example`. Do not run its "Migração" part: that pulls from a
   live production server, and this one is gone.
2. **Bring the stack up once**, so MySQL initialises its data folder. It
   loads the dev seed and creates the application users with the
   passwords from `.env`.
3. **Every database**: [Restore a database](#restore-a-database), steps
   1–4, dropping each seeded database first, from **one** run.
4. **The MySQL backup user**: the init script created it with `.env`'s
   password, but check the grants as in [MySQL backup user](#mysql-backup-user).
5. **The files**: [Restore files](#restore-files), from the same run.
6. **The erasure log**, from the **newest** run, then replay it into every
   restored database (step 5 of the database restore).
7. **Migrations, caches, search index**: `propel migration:status`, the
   phpBB `cache:purge` on both forums, then rebuild both search indexes.
8. **The backup itself**: the cron entries in [What runs, and when](#what-runs-and-when),
   the service-account keys and the verifier key at the paths `.env`
   names, then `scripts/backup-run.sh` once by hand. A restored server
   that is not backing itself up is the next incident.
9. **Event archives** need nothing: they stay on the Drive and the restored
   `evento_arquivamento` table still points at them.

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
tail -50 /var/log/baja-backup.log
```

**2. What did it say?** The script logs each phase. Re-run it by hand and
watch:

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
./scripts/backup-run.sh
```

**3. Read the first ERROR line, not the last.** Every failure mode has its
own message:

| Message contains | Means |
|---|---|
| `cannot connect to MySQL` | MySQL is down, or the backup user's password changed |
| `not visible to 'backup'` | The GRANT was lost — see [MySQL backup user](#mysql-backup-user) |
| `cannot list gdrive:` | Service-account key, Drive ID, or the Contributor role |
| `service-account key not readable` | The key file is missing on the host (docker then mounts an empty folder in its place) |
| `insufficient free disk` | The VPS is full. Nothing else will work either |
| `no '-- Dump completed' trailer` | The dump was truncated — MySQL died mid-run |
| `an age SECRET key is configured` | Someone pasted a private key into `.env`. Fix immediately |
| `exceeded BACKUP_DUMP_TIMEOUT` | The dump hung, usually a network partition |
| `is not mounted into the backup container` | A forum folder is missing: run without the prod overlay, or `/srv/baja` moved |
| `erasure log folder ... is not mounted` | `ERASURE_LOG_DIR` in `.env` is wrong, or the folder was never created |
| `another backup-run.sh is still running` | Yesterday's run has not finished. Find out why before killing it |

**The archive monitor** (`KUMA_PUSH_URL_ARCHIVE`) alerts when a pending
event archive failed. The reason is on the event's page in the judge area,
next to "Finalizado?", and in the `[backup-run] archive ...` lines of the
log. The request stays pending and is retried every night; unmarking the
event cancels it.

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

## Event archives

When an admin marks an event **Finalizado?** in `juiz/evento.php`, its
published results are frozen in the backups, so that what was published
can always be shown later — even after a correction, a code change that
computes differently, or the event being deleted (BAJAAPP-67).

**How it works.** The page does nothing slow. Saving the event adds one
row to `evento_arquivamento` (estado `pendente`), in the same transaction
as the save; unticking the box before the night cancels it. The page shows
the state next to the checkbox. At night `scripts/backup-run.sh`, after
the backup:

1. runs `php scripts/results-snapshot.php --update --dir <tmp>/snapshots --events <EVT>`
   in the `results-archive` service, as the **read-only** backup MySQL user
   (and the tool makes every session read-only on top);
2. runs `scripts/archive-event.sh` in the backup image, which tars that
   folder, encrypts it to both recipients and uploads
   `events/<EVT>/<YYYYMMDDTHHMMSSZ>/results.tar.gz.age`, its sidecar, and
   then the manifest;
3. marks the row `arquivado` with that folder, or records the failure and
   leaves it pending for the next night.

Unticking and ticking again later makes a new request and a new folder;
old archives are never replaced, and the pruner never looks at `events/`.
A request whose event is no longer finalizado when the job runs is
cancelled rather than archived.

The manifest of an archive:

```json
{
  "kind": "event-archive", "schema_version": 1,
  "event": "26BR", "request_id": 12,
  "requested_utc": "2026-10-20T21:15:00Z",
  "archived_utc": "2026-10-21T07:01:02Z",
  "code_version": "v4-12-gabc1234",
  "snapshot_count": 58,
  "artifact": { "file": "results.tar.gz.age", "sha256": "...", "tree_sha256": "...", ... }
}
```

`code_version` is `git describe --tags` of the deployed checkout, or
`unknown`. The snapshots are what that code computed from the data that
night.

**List the archives.**

```
rclone lsf --dirs-only gdrive:events/              # events that have one
rclone lsf --dirs-only gdrive:events/26BR/         # every archive of 26BR
rclone cat gdrive:events/26BR/20261021T070102Z/manifest.json | jq .
```

Or, for what the database recorded:

```
docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" baja_resultados \
    -e "SELECT arquivamento_id, evento_id, estado, pasta, tentativas, ultimo_erro FROM evento_arquivamento ORDER BY arquivamento_id"'
```

**Compare an archive with the live results.** Decrypt it, put the
snapshots where the baja-app container can read them, and run the
snapshot tool's `--check` against them (it only reads; exit code 0 means
the live results equal what was archived, otherwise it prints every cell
that differs, as in `docs/results-snapshots.md`):

```
rclone copy gdrive:events/26BR/20261021T070102Z ./archive-26BR/
cd archive-26BR && sha256sum -c results.tar.gz.age.sha256
age -d -i /path/to/key results.tar.gz.age | tar -xzf -      # -> snapshots/26BR/*.json
docker cp snapshots baja-app:/tmp/archive-26BR
docker compose exec --user "$(id -u):$(id -g)" baja-app \
    php scripts/results-snapshot.php --check --dir /tmp/archive-26BR --events 26BR
```

Run it with the read-only account as in the "Pre-release check" of
`docs/results-snapshots.md` when it is pointed at production data. The
archived files are published results; treat a decrypted copy like the
data it came from and delete it afterwards.

---

## Weekly verification

An untested backup is a hypothesis. Every Tuesday at 04:00 the newest
`daily/` run is downloaded, decrypted, imported into a throwaway MySQL,
and compared against its own manifest; every file set is extracted and
compared, file by file, with the manifest's description; the erasure log
is decrypted and its chain checked; and every event archive is restored
and compared with its own manifest.

```
cd /srv/baja/baja-sae-brasil-online/baja-infra
./scripts/verify-run.sh
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

The restored files live in the verifier container's work folder for the
length of one check each and are removed straight after; the container is
`--rm`. Every run prints the peak memory it observed. Measure, do not
assume:

```
[verify-run] peak memory observed across the verify stack: 222.9 MiB (mem_limit is 768 MiB)
```

### Hard versus soft

**Hard** — nothing arrived, a checksum mismatch, a failed import, a row
count that disagrees with the manifest, a mangled charset canary, restored
files that differ, an erasure log whose chain broke in transit, an event
archive that does not restore, or a failed teardown. These suppress the
Kuma heartbeat and the monitor alerts.

**Soft** — schema drift since the previous run, a large swing in total row
count, an erasure log whose chain was already broken at the source, an
event folder without a manifest (an upload that died; the job retried
into a new folder). These are logged under `for review` and do **not**
alert. Noisy checks train people to ignore the alert that matters, so
resist the urge to promote one.

Schema drift is soft on purpose: schema changes have been made directly in
MySQL before, so this is a free drift detector, and failing on it would
page someone for every legitimate migration.

### Reading the results

Results are **per artifact**, so a corrupt `phpbb_formula` never masks a
healthy `baja_resultados`:

```
[verify] ----- results -----
[verify]   baja_resultados: OK (restored in 1s)
[verify]   phpbb_baja: OK (restored in 2s)
[verify]   phpbb_formula: CHECKSUM MISMATCH
[verify]   phpbb_baja-files: OK (1843 files, 412.7 MiB)
[verify]   phpbb_formula-files: OK (95 files, 21.0 MiB)
[verify]   baja-app-sons: OK (6 files, 1.2 MiB)
[verify]   erasure-log: OK (3 entries, chain intact)
[verify]   events/26BR/20261021T070102Z: OK (58 snapshots, v4-12-gabc1234)
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
| `certificate lookup is missing ...` / `query failed` | The restore imports but cannot produce a PDF, or the schema moved away from what the check reads |
| `FILES DIFFER` / `CONTENT DIFFERS` | The file set does not restore to what was archived. Do not trust it; check the previous run |
| `ENTRIES DIFFER` / `CHAIN BROKEN` | The erasure log did not survive the round trip. Keep the live one safe |
| `events/...: CONTENT DIFFERS` | That event's archive is damaged. The next re-mark makes a new one; investigate first |
| `only the databases can be checked` | A manifest from before file sets existed (schema 2). Expected only for old runs |

The certificate lookup is the check that matters. Certificates are
generated on demand, so "the backup restored" means nothing until a
certificate can actually be produced from it. It picks its subject by a
deterministic rule — the first non-annulled certificate by token on a
certificate-issuing event — and never by a hardcoded CPF, because this
repository is public.

### "LEFTOVERS from a previous run were found"

The previous run did not tear itself down, which means a volume holding
**decrypted production data** sat on the production host until this run
destroyed it. The run continues, but this is a compliance incident, not a
warning to scroll past. Find out why the last run died — check
`/var/log/baja-verify.log` around its start time.

### Testing it without Google Drive

`VERIFY_REMOTE=:local:/drive/` and `VERIFY_LOCAL_DRIVE` point the verifier
at a local directory laid out like the Shared Drive — the one a backup
with `BACKUP_REMOTE=:local:/drive/` wrote into `BACKUP_LOCAL_DRIVE`. The run
logs a loud warning and never pings Kuma. `tests/backup/stack-test.sh` does
the whole round trip this way; see [Acceptance tests](#acceptance-tests).

---

## Retention tiers, and reaching an old artifact

| | `daily/` | `monthly/` | `yearly/` |
|---|---|---|---|
| `baja_resultados` | 35 days | 24 months | indefinite |
| `phpbb_baja`, `phpbb_formula` and their `-files` | 35 days | 6 months | not written |
| `baja-app-sons`, `erasure-log` | 35 days | 24 months | not written |
| `events/` | — | — | — (kept indefinitely, never pruned) |

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
can no longer verify. A forum's attachments go with its posts.

`yearly/` holds `baja_resultados` alone. The certificate justification
does not extend to forum history. The erasure log needs no yearly copy:
it is append-only, so the newest `daily/` copy always holds everything an
older one does.

`events/` is kept for good on purpose: it is the record of what was
published, holds public results only, and grows by a few small archives a
year.

To reach an old artifact:

```
rclone lsd gdrive:monthly
rclone lsd gdrive:yearly
rclone copy gdrive:yearly/20270101T070000Z ./restore/ --progress
```

A manifest in `monthly/` or `yearly/` describes the run **as created**, so
it may reference artifacts that tier no longer holds. That is by design,
not corruption.

### What enforces this

A Google Apps Script pruner, source in
[`baja-infra/gas/retention/`](../gas/retention/README.md), running daily
under a **dedicated role account** — not a person's, because Apps Script
triggers execute as whoever installed them and a trigger bound to a
student who graduates is exactly the failure this work package exists to
prevent.

It trashes rather than deletes, refuses to take `daily/` below 7 runs or
`monthly/` below 6, caps itself at 30 files per run, is dry-run by
default, never lists `events/`, and logs every action to `retention-log/`
in the Shared Drive. If it aborts, do not just re-run it: an abort means
either the backup job has stopped producing runs or the pruner has a bug.

**Do not substitute a deduplicating backup tool** (restic and similar) to
shorten these tiers further. Dedup operates on the uncompressed stream, so
it would replace both the gzip and `age` layers, make the encryption
symmetric with the repo password sitting on the VPS, and require delete
rights for `prune` — which kills the Contributor-cannot-trash property and
the separate pruner identity with it. It would also turn one corrupt
artifact into damage spanning many snapshots. That trades recovery-path
simplicity for storage that is not scarce. Revisit only if Drive quota
becomes a real constraint — the forum attachments are the part most
likely to make it one; their size is logged by every run's preflight.

### Secondary copies

The pruner is not the last line of defence: a Manager can empty the Shared
Drive's trash, and nothing on Drive is undeletable. Any operator may keep
an independent copy by pulling the Shared Drive with the read-only
`baja-backup-reader` account, for example with rclone:

```
rclone sync gdrive: /path/to/copy --drive-scope drive.readonly
```

Because Drive has no write-once storage, such a copy should keep **its own
snapshots or versions** (filesystem snapshots, object versioning), and
those are the real retention floor: a plain mirror faithfully copies a
deletion. It should be watched by a check that does **not** run on the
same machine as the copy, since a machine cannot report its own absence.
The artifacts are encrypted, so the copy needs no key — and should not
hold one. A secondary copy also doubles as proof that the artifacts are
readable from somewhere that is not the VPS.

---

## MySQL backup user

`mysql/init/02-create-users.sh` creates it, but **init scripts only run on
a database's first boot**. Production was initialised long ago, so the
user has to be created by hand there — once:

```sql
CREATE USER IF NOT EXISTS 'backup'@'%' IDENTIFIED BY '<MYSQL_BACKUP_PASSWORD from .env>';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON baja_resultados.* TO 'backup'@'%';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON phpbb_baja.* TO 'backup'@'%';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON phpbb_formula.* TO 'backup'@'%';
GRANT SHOW_ROUTINE ON *.* TO 'backup'@'%';
FLUSH PRIVILEGES;
```

The same applies to an existing dev database created before this user
existed; `docker compose down -v && docker compose up -d` is the other way.

Each grant earns its place against a `mysqldump` flag: `SELECT` reads the
rows, `SHOW VIEW` dumps view definitions, `TRIGGER` is what `--triggers`
needs, `EVENT` is what `--events` needs, `LOCK TABLES` is historically
required and harmless alongside `--single-transaction` (which takes no
table locks), and the global `SHOW_ROUTINE` (read-only) is what lets
`--routines` read procedures another account defined.

Per database, never `ON *.*`: `SELECT ON *.*` would include the `mysql`
schema and every account's password hash.

There is no `INSERT`, `UPDATE`, `DELETE`, `CREATE` or `DROP`, so a
compromised backup container cannot alter production. `TRIGGER` is the one
grant that is not purely read-only — it also permits `CREATE`/`DROP
TRIGGER` — but it is the minimum MySQL offers for dumping triggers. If a
future change appears to need more, widen it deliberately and write down
why; do not reach for root.

The event archives' snapshot runs as this same user, so the results pages
it executes cannot write either.

Confirm it is read-only:

```
docker compose exec mysql mysql -u backup -p -e \
    "CREATE TABLE baja_resultados.should_fail (i INT);"
# expected: ERROR 1142 (42000): CREATE command denied
```

---

## Configuration

Everything lives in `baja-infra/.env`. See `.env.example` for the
annotated list. Three things about it are load-bearing:

- **Comments go on their own line, never trailing an assignment.** See the
  top of `.env.example`.
- `GDRIVE_SA_WRITER_JSON` is used **two ways**: in `.env` it is the HOST
  path to the service-account key, which compose bind-mounts; inside the
  container the same variable name holds the fixed read-only path the key
  appears at. A missing host file is mounted as an empty folder, which
  preflight reports as "not readable".
- `ERASURE_LOG_DIR` must be `/srv/baja/erasure` in production and on
  staging. Its default in `.env.example` is a gitignored dev folder.

---

## Erasure log

An append-only record of LGPD subject erasure requests, replayed after any
restore. It is the mitigation for the fact that backups make deletion less
final than it looks: historical artifacts are **not** rewritten, so the
restore procedure has to know what must not come back.

It **contains personal data** and therefore lives outside this repository —
the repo is public. It is backed up every night like everything else,
encrypted to both recipients; the backup reads it through a read-only
mount, and the `erasure-log` compose service is its only writer.

```
sudo install -d -m 700 /srv/baja/erasure
sudo touch /srv/baja/erasure/erasure-log.jsonl
sudo chmod 600 /srv/baja/erasure/erasure-log.jsonl
sudo chattr +a /srv/baja/erasure/erasure-log.jsonl    # genuinely append-only
```

`chattr +a` means even root must clear the attribute before editing, which
turns a careless edit into a deliberate one. Each line also carries the
SHA-256 of the previous line, so `verify` detects an edit or a deletion in
the middle of the file. That is tamper *evidence*, not prevention. Every
backup run checks the chain and records the result in its manifest.

Recording an erasure (**after** you have erased it in production):

```
docker compose --profile tools run --rm erasure-log \
    record --request REQ-2026-014 \
           --database baja_resultados --table participantes --column cpf \
           --value '<the CPF named in the request>' \
           --note "Art. 18 request, ticket 014"
```

Replaying after a restore is [step 5](#5-replay-erasures--do-not-skip-this)
above. Check the chain periodically:

```
docker compose --profile tools run --rm erasure-log verify
```

---

## What is not in the backups

| State | Why not |
|---|---|
| `.env`, service-account keys, age private keys, Cloudflare tokens | Secrets; kept by the maintainers. See [Secrets](#secrets-kept-by-the-maintainers-not-by-the-backups) |
| The raw MySQL data folder (`/srv/baja/mysql`, `mysql_data`) | A copy of live InnoDB files is not consistent; the logical dumps are |
| Redis (`redis_data`) | Caches, rate-limit counters, phpBB session lookups, lap-count snapshots and notices: all rebuilt or expired by design. The `/buscar` backoff counters are keyed hashes of document numbers and must not outlive their minutes |
| The rest of the `phpbb_*_html` volumes | Code, `ext/`, language packs come from the image; `cache/` regenerates; `config.php` is rendered from `.env` at boot and holds a database password; `store/` is phpBB's own scratch |
| phpBB sessions, login attempts, search index tables | Excluded from the dumps: ephemeral or regenerable (see `backup_excluded_tables`) |
| Lap-count snapshot files in the baja-app container's temp folder | A copy of what Redis holds, recomputed from the database |
| Certificates (PDFs) | Generated on demand from `baja_resultados` |
| `/etc/letsencrypt` | Re-issued by certbot on a new server |
| The verify stack's scratch volume | Decrypted production data, destroyed after every run, on purpose |
| Mailpit | Dev only; holds whatever you typed into the forms |

If a feature starts writing files somewhere else — `STORAGE_ENCRYPTION_KEY`
was introduced for encrypted file storage that `develop` does not use yet —
add that folder as a file set in `scripts/backup-lib.sh`
(`BACKUP_FILE_SETS`), mount it read-only into the backup service, and add
it to the pruner's rules if its retention differs.

---

## Human restore drill

**Twice yearly, a maintainer who did not build this performs a restore
from scratch using the escrow key and this document alone.** Bus factor is
the entire point.

Automation verifies the artifact. It cannot verify the *recovery path*,
because an automated verifier encodes exactly the knowledge you are testing
whether a human has.

Rules:

- On a machine that is not the VPS.
- From the Shared Drive and the escrow key only. No repo checkout, no help
  from whoever built this.
- Follow [Restore a database](#restore-a-database) and
  [Restore files](#restore-files) verbatim. Where they are wrong or
  unclear, that is the finding — fix the document, do not fix it in your
  head.
- Record: date, who (a role is enough here), elapsed time, and everything
  the runbook got wrong.

| Date | Who | Duration | What the runbook got wrong |
|---|---|---|---|
| _(pending — first drill due after the backups go live)_ | | | |

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

**The artifacts are snapshots seconds apart, not one atomic snapshot.**
`baja_resultados.user` rows correspond to phpBB user IDs, so a
registration landing mid-run can end up on one side of the boundary. The
symptom is the known unprovisioned-user path: the user is bounced to login
and an admin adds the row. The file sets are taken after the dumps, so an
attachment uploaded in between is in the files and not in the posts (an
orphan file, harmless). Do **not** "fix" it by dumping all three in one
transaction and splitting the file on `-- Current Database:` markers; that
puts dump-parsing on the critical path of recovery.

**"Cannot trash" is not "cannot damage".** A Contributor cannot trash, but
can upload a new revision of an existing file. A compromised writer could
therefore overwrite old artifacts with garbage. Drive keeps the previous
revision of a binary file for 30 days, a Manager can restore it, and the
weekly verifier would flag the newest run — but older tiers are only
protected by a [secondary copy](#secondary-copies) with its own snapshots.

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

**Only uploads are backed up from the forum volumes.** Anything an admin
added through the ACP that lands outside `files/` and
`images/avatars/upload/` — custom smilies, rank images, post icons — lives
only in the `phpbb_*_html` volume and is lost on `down -v`. Check whether
production has any (`phpbb_smilies`, `phpbb_ranks` rows pointing at files
the stock package does not ship) before relying on this.

**An event archive is what the code computed that night.** It runs after
the backup on the night following the mark, not at the moment of the
click. A result changed in between, with the event still finalizado, is
archived as changed.

---

## Acceptance tests

Re-run these after changing anything in `scripts/` or `docker/backup/`.

**Automated, no Docker:** `baja-infra/tests/backup/run-tests.sh` (CI runs
it) covers the file set round trip with both keys, a missing forum folder
stopping the run, the verifier noticing restored files that differ, the
manifest's contents and its personal-data guard, the manifest going last in
every tier (tests 1 and 10 below without a database), the erasure log
round trip, and the event archives. The pruner's tests are
`node baja-infra/gas/retention/test/prune.test.js`. The event archive queue
is `vendor/bin/phpunit --testsuite results-archive-db` (BAJA_TEST_DB=1).

**Automated, on a running dev or staging stack:**
`baja-infra/tests/backup/stack-test.sh` runs `backup-run.sh` and
`verify-run.sh` end to end with throwaway keys and a local "Drive" (tests 1,
4–7 below, and an event archive).

By hand:

1. **A run produces the full set.** `scripts/backup-run.sh`, then confirm
   `daily/<run_id>/` holds seven artifacts, seven `.sha256` sidecars and a
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

6. **Per-artifact reporting.** A corrupt `phpbb_formula` must not mask a
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
    refusing to breach a floor, and pruning `phpbb_*` dumps and files from
    a six-month-old monthly folder while leaving `baja_resultados`.

12. **One full manual restore on a different machine**, from the artifacts
    alone — databases and files.

13. **`shellcheck` is clean**, and no secret, dump or real personal data
    appears anywhere in branch history.

14. **An event archive end to end.** Tick "Finalizado?" on a staging
    event, run `scripts/backup-run.sh archives`, and check the event page
    says "Resultados arquivados em ...", then compare the archive with the
    live results as in [Event archives](#event-archives).

### Running tests 1, 3 and 10 without Google Drive

`BACKUP_REMOTE=:local:/drive/` redirects uploads to `BACKUP_LOCAL_DRIVE`
on the host and suppresses the Kuma heartbeat, so a test run cannot mark
the monitor healthy:

```
BACKUP_REMOTE=":local:/drive/" BACKUP_RUN_ID=20260101T040000Z \
    docker compose --profile tools run --rm backup
ls backup-local/drive/daily/
```

It logs a loud warning on every such run. Leave `BACKUP_REMOTE` empty in
production.
