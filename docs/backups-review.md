# Backups: readiness review (BAJAAPP-4, BAJAAPP-67)

A review of the unfinished `feature/db-backups` branch (5 commits, last on
2026-08-17) against `develop` as of 2026-10-25, and of what this PR
(`chore/db-backups`) changes. The runbook itself is
[`baja-infra/docs/backup-restore.md`](../baja-infra/docs/backup-restore.md).

## Summary

- **The August design is sound and worth keeping.** Its four safety
  properties still hold, and this PR keeps all of them: every artifact
  encrypted to two age recipients (offline escrow + verifier); a Drive
  writer that cannot trash; the manifest uploaded last as the mark of a
  complete run; and verification by actually restoring into a scratch
  MySQL.
- **It was not production-ready, and could not have been switched on as
  it stood.** The weekly verifier failed on today's schema. The forum
  attachments and avatars were explicitly out of scope. The erasure log
  was neither reachable from its own tool nor backed up. The backup user
  could read every account's password hash. And there was no restore path
  onto a fresh server that would have worked.
- **None of it has ever run against Google Drive or production.** No
  acceptance test result is recorded on the branch, and the restore drill
  table is empty. The pruner's logic is tested against a fake Drive.
- **This PR closes the gaps that don't need the maintainer.** It covers
  every piece of state on today's `develop` (three databases, both forums'
  uploads, generated timer sounds, the erasure log), restores and verifies
  each one, and adds event result archives (BAJAAPP-67).
- **What is left needs a person with the accounts:** the Google identities
  and Shared Drive, the age keys, Kuma, cron, the production MySQL user,
  and a dry-run week for the pruner. See the
  [checklist](#maintainer-setup-checklist).

## What the branch did, phase by phase

| Phase | What it built | State found |
|---|---|---|
| 1. Nightly backup (`backup-db.sh`, `backup-lib.sh`, `docker/backup/`) | One `mysqldump --single-transaction` per database, gzip, age to both recipients, sha256 sidecars, a manifest with per-table counts and a charset canary, upload to `daily/` (and `monthly/`, `yearly/`), manifest last, Kuma heartbeat on success only. A read-only `backup` MySQL user. | **Finished, never run for real.** It merged cleanly with today's compose. Bugs: `SELECT ON *.*` (includes `mysql.user`); `BACKUP_RUN_ID` not validated before becoming a remote path; the dev mode (`BACKUP_REMOTE=:local:...`) wrote inside a `--rm` container, so its output vanished; a missing service-account file becomes a bind-mounted empty folder that passed the `-r` check. |
| Erasure log (`erasure-log.sh`) | Append-only, hash-chained LGPD erasure record, replayed after a restore. | **Unusable as wired.** `/srv/baja/erasure` was never mounted into the container it is run through, so `record` died on a missing folder. The log itself was never backed up, so losing the VPS meant every restore resurrected erased people. |
| 2. Weekly verification (`verify-run.sh`, `verify-db.sh`, `docker-compose.verify.yml`) | A capped scratch MySQL on its own network, restore of the newest run, freshness, checksums, row counts, canary, floors, a real certificate lookup, teardown proven from the host, Kuma only after teardown. | **Broken on today's develop.** The certificate check ordered by `participantes.idparticipantes`, which the certificates release replaced with `token`. Under `set -e` the failed query ended the run before any report: it would have alerted every Tuesday with no explanation. It also counted annulled certificates. Otherwise complete; never run on the VPS (peak memory not measured). |
| 3. Retention pruner (`gas/retention/`) | Apps Script under a role account: trash-only, per-tier floors, 30-file cap, dry run by default, log in the Drive. 16 tests against a fake Drive. | **Finished, tests pass, never deployed.** Untested assumptions about the real Drive API (see [Risks](#risks)). |
| CI | shellcheck of `scripts/` and `mysql/init/`, compose validation of the verify stack, pruner tests. | Worked on the branch. On today's develop it would have broken the results-snapshots job: `02-create-users.sh` runs there under `set -u` and now needs `MYSQL_BACKUP_USER`. |
| Docs (`backup-restore.md`) | A thorough on-call runbook. | Database-only. Restore commands assumed a bare `mysql` rather than the compose stack. Gave no order for a fresh server, where the first MySQL boot loads the dev seed (importing over it merges). Named the key holders and a private NAS. |

## What must be backed up on today's develop

| State | Where | Branch covered it? | Now |
|---|---|---|---|
| `baja_resultados` (≈30k participant rows, certificate requests, results since 2003, lap counting) | MySQL (`/srv/baja/mysql`) | yes | yes; verifier fixed |
| `phpbb_baja`, `phpbb_formula` | MySQL | yes | yes |
| Forum attachments, both forums | `/srv/baja/phpbb-*/files` (prod), inside `phpbb_*_html` (dev) | **no** ("out of scope") | file sets `phpbb_*-files`, verified by extraction |
| Forum avatars, both forums | `/srv/baja/phpbb-*/avatars` (prod) | **no** | in the same file sets |
| Timer sounds generated by TTS | `baja-php/juiz/sons/gerados/` in the checkout | **no** | file set `baja-app-sons` |
| LGPD erasure log | `/srv/baja/erasure/` | **no** (and not mounted) | backed up encrypted nightly, chain checked; `erasure-log` service is its only writer |
| Published results of finished events | derived from `baja_resultados` + the code of the day | no | event archives (`events/<EVT>/<ts>/`) |
| "Encrypted uploaded files" | — | — | **none exist on develop.** `Baja\Util\Encryption` (keyed by `STORAGE_ENCRYPTION_KEY`) has no caller yet, and nothing in the PHP app accepts an upload. The key still has to be kept (below). The runbook says how to add a file set when the first feature writes encrypted files. |
| Admin-uploaded smilies, ranks, post icons | inside `phpbb_*_html` only | no | **not covered**: not bind-mounted in production either, so already lost on any `down -v`. To check on production; see Risks. |

### What must never go into backups, and is not

- The Google service-account keys, the age private keys, the Cloudflare
  tokens. These are secrets that can be re-issued (below). The `.env` is
  the exception and is now backed up, but only to the escrow key.
- The raw MySQL data folder. It is not consistent while live; the logical
  dumps are.
- Redis: caches, rate-limit counters, phpBB session lookups, lap-count
  snapshots. All are rebuilt or meant to expire. The `/buscar` backoff
  counters are keyed hashes of document numbers and must not outlive
  their minutes.
- The rest of the forum volumes: code from the image, regenerable
  `cache/`, phpBB's `store/`, and `config.php`, which holds a database
  password. A test asserts that `config.php` stays out.
- phpBB sessions, login attempts and the search index tables (excluded
  from the dumps).
- The verify stack's decrypted scratch volume (destroyed and proven gone).
- Certificates (generated on demand), `/etc/letsencrypt` (re-issued),
  Mailpit (dev).

### Secrets: how they are kept instead

Production's `baja-infra/.env` existed in one place besides the server:
one maintainer's password manager. Every night's run now also keeps a
copy of it on the Shared Drive under `secrets/<run_id>/env.age`. That
copy is encrypted to the **escrow key alone**, not the verifier, so
nothing on the server can read it. A new copy is uploaded only when the
`.env` changed; every manifest names the copy that matches its night.
The verify stack checks the copy (present, checksum, exactly one
recipient) without a key and never decrypts it.

So the secrets live in three places, described by role in the runbook:
- the Drive copy, readable by the escrow key only;
- each escrow holder's own password manager, holding the escrow private
  key and a current `.env`;
- an offline copy of the escrow private key, kept by each holder.

The secrets outside `.env` (service-account keys, the verifier key,
Cloudflare tokens) are re-issued on a new server rather than copied. The
runbook's "Restore a server from nothing" section rebuilds a server from
only an escrow key, Drive access and the repository.

`STORAGE_ENCRYPTION_KEY` is the one whose loss no backup can repair. There
is no key versioning, so once any feature encrypts with it, losing the key
loses that data. Nothing encrypts with it on develop yet; the `.env` copy
now protects it from the day it is set.

## Restore: can a person follow it onto a fresh server?

Before this PR, no:

- **Fresh server:** no order of operations. The first boot of the MySQL
  container loads the dev seed, and the documented import would have
  merged production into it.
- **Commands** assumed a host `mysql` client with root access. The stack
  only exposes MySQL through its container.
- **Files:** nothing to restore.
- **Erasure log:** it was to be replayed after a restore, but nothing kept
  a copy of it off the VPS. The doc also did not say to replay the
  *newest* log rather than the one from the restored run, so erasures made
  since would have come back.
- **phpBB after restore:** the search index rebuild was there; the cache
  purge was not, and neither was the `restart nginx` that the dev-workflow
  FAQ makes mandatory after a forum container changes.
- **Migrations:** an old dump under newer code needs
  `propel migration:migrate`. Not mentioned.

Now there is a restore section for each piece, and a nine-step
fresh-server order: stack, databases (seed dropped first), backup user,
files from the same run, erasure log from the newest run and its replay,
migrations and caches, the backup itself. **Unproven until the first human
drill.** It is in the checklist below, and it is the only way to find what the doc
still gets wrong.

## Maintainer setup checklist

Everything here needs an account, a key or the server, which is why it is
not done in the PR. Order matters roughly top to bottom.

**Google (Workspace admin, or whoever owns SAE BRASIL's Drive)**
- [ ] Create the `baja-backups` **Shared Drive** (org-owned, not anyone's My Drive). Note its ID.
- [ ] Create the service accounts `baja-backup-writer` and `baja-backup-reader`, and download a JSON key for each.
- [ ] Add the writer as **Contributor** and the reader as **Viewer** on the Shared Drive.
- [ ] Create or choose the **role account** (not a person) as **Manager**. It owns the pruner.
- [ ] Put both JSON keys on the VPS at the paths `GDRIVE_SA_WRITER_JSON` / `GDRIVE_SA_READER_JSON` name (mode 0600).

**age keys**
- [ ] Generate the **escrow** pair offline (`age-keygen`). Each of the two escrow holders keeps the private key in their own password manager **and** in an offline copy (printed, or on a removable drive). Only the `age1...` public key goes into `.env` as `AGE_RECIPIENT_ESCROW`. This key alone can read the `.env` copies.
- [ ] Generate the **verifier** pair. Its private key goes on the VPS at `AGE_IDENTITY_VERIFIER` (0600); its public key goes into `.env` as `AGE_RECIPIENT_VERIFIER`. It cannot read the `.env` copies, by design.

**Production server**
- [ ] Deploy with the migration: `./propel migration:migrate` creates `evento_arquivamento`. If it is missed, admins can still edit events. They get an orange "archive not queued" warning, the error is logged, and the nightly archive monitor alerts. No finished event is queued until the migration runs.
- [ ] Create the MySQL `backup` user with the per-database grants (runbook, "MySQL backup user"). The init script does not run on an existing server. If an earlier version of this branch was ever applied by hand with `ON *.*`, revoke that.
- [ ] Create `/srv/baja/erasure/erasure-log.jsonl` (0700 folder, 0600 file, `chattr +a`). Set `ERASURE_LOG_DIR=/srv/baja/erasure`.
- [ ] Fill in the backup block of `.env`: `MYSQL_BACKUP_PASSWORD`, `MYSQL_VERIFY_ROOT_PASSWORD` (different from root's), `GDRIVE_SHARED_DRIVE_ID`, the recipients and paths above, and `VERIFY_MIN_ROWS_PARTICIPANTES` (production's `participantes` count, about 31,000, minus a margin; the default is 20000).
- [ ] Measure: `sudo du -sh /srv/baja/phpbb-*/files /srv/baja/phpbb-*/avatars`. If the attachments are several GB, 35 daily copies plus monthlies may matter for Drive quota. Tell whoever maintains the backups before switching on.
- [ ] Check whether either forum has ACP-uploaded smilies, ranks or icons outside the stock set. If so, bind-mount those folders and add them to the file sets.
- [ ] Add both cron entries (runbook, "What runs, and when").
- [ ] Run `scripts/backup-run.sh` by hand once, then the acceptance tests in the runbook, especially **#2: the writer cannot trash**.
- [ ] Run `scripts/verify-run.sh` by hand with production up, and note the peak memory it prints.

**Uptime Kuma** (on a machine other than the VPS)
- [ ] Four push monitors: backup (daily, about 26h grace), event archives (daily), verify (weekly, about 8 days), prune (daily). Put their URLs in `.env` (`KUMA_PUSH_URL_BACKUP`, `_ARCHIVE`, `_VERIFY`) and in the pruner's script properties (`KUMA_PUSH_URL_PRUNE`).

**Pruner**
- [ ] As the role account: `clasp push`, script properties, daily trigger (`gas/retention/README.md`).
- [ ] One week in dry run; a person reads `retention-log/`; then `DRY_RUN=false`. The prune monitor stays silent during the dry run (it only pings on live runs). Pause it or expect the alert.

**Secrets and people**
- [ ] After the first nightly run, confirm `secrets/<run_id>/env.json` exists and the run's manifest names it (`env.folder`). An escrow holder decrypts it once, on their own machine, to prove the key works, then deletes the plaintext.
- [ ] Schedule the first human restore drill, by a maintainer who did not set this up, on a machine that is not the VPS. At least once a year, start it from the runbook's "Restore a server from nothing".
- [ ] Optional: a [secondary copy](../baja-infra/docs/backup-restore.md#secondary-copies) with its own snapshots, pulled with the reader account.

## Risks

- **Nothing here has met real Google Drive.** The upload, the
  Contributor-cannot-trash property, rclone's Shared Drive flags and the
  Apps Script calls are all exercised against fakes. First-run surprises
  are likely. That is what the acceptance tests and the dry-run week are
  for.
- **"Cannot trash" is not "cannot damage".** A Contributor can upload new
  revisions, so a compromised VPS could overwrite old artifacts. Drive
  keeps the previous binary revision for 30 days. Beyond that, only a
  secondary copy with its own snapshots protects the older tiers.
- **The pruner on the real Drive API:** whether `Folder.getFiles()`
  returns already-trashed files is untested. If it does, rule 2 would
  re-trash the same files daily, and they count against the cap. Look for
  this in the dry-run log.
- **The verify stack runs beside production** on a 4 GB VPS. It is capped
  at 768 MB, but nobody has measured it there yet.
- **The backup container now reads the whole `.env`** to make the escrow-only copy. It already held a password that reads every row. It still cannot decrypt what it uploads, and it has no docker socket.
- **The VPS holds everything at once.** The backup container can read every
  row, and the verifier's private key lives there. That is inherent to
  running both on one machine. The escrow key, kept off it, is what makes
  a stolen VPS not equal to stolen backups.
- **Drive quota and size.** Full nightly copies of the forum attachments
  multiply by about 40 under the retention tiers. This is fine at small
  sizes; measure first (checklist).
- **Event archives depend on the nightly job.** An archive is what the code
  computed the night after the mark, not at the click. A request that keeps
  failing alerts every night. Unmarking the event cancels it.
- **The queue table has no generated Propel classes.** `model:build` could
  not be run where this PR was written, and nothing uses the classes
  (`Baja\ResultsArchive\Queue` is plain SQL). The next `model:build` will
  add them, a harmless diff to commit then.
- **Out of scope, but seen on the way:** `juiz/evento.php` has no CSRF
  protection, so a forged cross-site POST from a logged-in admin's browser
  can mark events finalizado or delete them. This deserves its own ticket.

## What I would simplify

The design was written before the current stack. Most of its complexity
earns its place: the three identities, the separate pruner, and
manifest-last are what make "a compromised VPS cannot destroy the backups"
true. Smaller simplifications:

- **Drop `LOCK TABLES` from the backup grants.** With
  `--single-transaction` it is unused. It is harmless, but it is one more
  grant to explain.
- **Copy `monthly/` and `yearly/` on the Drive side.** They are separate
  uploads of the same bytes today; `rclone copyto` within the Shared Drive
  copies server-side. This only matters if upload time grows.
- **If the attachments turn out large, back them up weekly, not nightly.**
  Posts and attachments then drift by up to a week, but attachments are
  write-once, and that trade is better than 35 identical copies. Better
  than either is checking the size first.
- **Move verification off the VPS eventually.** A trusted machine with the
  verifier key would remove the memory risk and the "decrypted data on the
  production host" concern together. Do not use CI: it would put decrypted
  personal data on someone else's runners.

## What this PR changes

- Merges `feature/db-backups` with a merge commit, so its five commits
  stay as they were. Fixes the conflicts with today's `.gitignore` (LF
  conversion, test fixtures re-admitted) and CI (`set -u`).
- Fixes the verifier's certificate check, the backup grants, and run id
  validation.
- Adds the file sets, the erasure log backup and its writer service, the
  local test destination, verification of all three, and the pruner rule
  for forum attachments.
- Adds the escrow-only `.env` copy under `secrets/`, uploaded only when the
  `.env` changes and named in every manifest, with a restore-from-nothing
  procedure.
- Adds event archives (BAJAAPP-67): the `evento_arquivamento` table and
  migration, the trigger in `juiz/evento.php`, `Baja\ResultsArchive\Queue`,
  `scripts/results-archive.php`, `archive-event.sh`, and `backup-run.sh`
  as the new cron entry. Saving an event never depends on the queue.
- Tests, in CI: `baja-infra/tests/backup/run-tests.sh` (file set, erasure
  and event round trips, the manifest, manifest-last), the pruner's
  tests (two new ones), and the `results-archive-db` suite (trigger and
  consumption). `baja-infra/tests/backup/stack-test.sh` runs everything
  end to end on dev or staging.
- Rewrites the runbook and removes TrueNAS and named people from the repo.

### What has been run, and where

This PR was written on a machine without Docker, age or Composer, so the
tests could not run there. Only shellcheck (clean), `php -l`, the pruner
tests (18 pass) and a smoke test of the file-tree digest ran locally. The
backup script tests and the PHPUnit suite first run in this PR's CI.
`stack-test.sh` has not been run yet. Run it on the dev stack and on
staging before merging.
