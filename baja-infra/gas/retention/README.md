# Retention pruner (Google Apps Script)

Enforces the retention tiers on the `baja-backups` Shared Drive. Runs
daily, reports to Uptime Kuma, and is version-controlled here rather than
existing only in a browser tab — which is where Apps Script normally goes
to be forgotten.

**This is the most dangerous code in the backup system.** Everything else
can only fail to help; this can actively destroy. Read
[`Code.js`](Code.js)'s header before changing anything in it.

## Rules

1. `daily/<run_id>/` older than **35 days** → trash the whole folder.
2. In `monthly/<run_id>/` older than **6 months** → trash the two
   `phpbb_*` artifacts and their sidecars, leaving `baja_resultados` and
   the manifest.
3. `monthly/<run_id>/` older than **24 months** → trash the whole folder.
4. `yearly/` → **never touched.** Manual review only.

Age comes from the **run id in the folder name**, not from Drive's
created date: the run id is what the artifact means, and Drive's
timestamp resets on a copy or a re-upload.

## Guardrails

| | |
|---|---|
| Trash, never permanent-delete | Drive's 30-day trash is a second net. "Expires at 35 days" really means gone at ~65 |
| Floor per tier | Never below 7 `daily/` runs or 6 `monthly/` runs. Breaching one **aborts the whole run** |
| Hard cap | At most 30 files per run. A runaway is a bug; this makes it a small one |
| Dry run by default | Real deletion needs `DRY_RUN` set to exactly the string `false` |
| Plan, then execute | Nothing is trashed until the full plan has passed every guardrail |
| Never trash a manifest | Except as part of removing its whole folder — it is the record of what a run contained |
| Log everything | File name, age and rule, to `retention-log/` in the Shared Drive |

An abort is not a retry-next-time condition. It means either the backup
job has stopped producing runs or this script has a bug, and both need a
human before anything else is trashed.

## Who it runs as

**A dedicated role account, never a person's.** Apps Script triggers
execute as whoever installed them, so a trigger bound to a student who
graduates is precisely the failure mode this work package exists to
prevent. That account is a **Manager** on the Shared Drive; it is the only
one of the three identities that can trash anything, and it never touches
the VPS.

## Setup

Log in to `clasp` **as the role account**, not as yourself.

```
npm install -g @google/clasp
clasp login

cd baja-infra/gas/retention
cp .clasp.json.example .clasp.json     # then fill in scriptId
clasp push
```

Script properties (Apps Script editor → Project Settings → Script
Properties):

| Property | Value |
|---|---|
| `SHARED_DRIVE_ID` | ID of the `baja-backups` Shared Drive |
| `KUMA_PUSH_URL_PRUNE` | Uptime Kuma push URL for this job |
| `DRY_RUN` | `true` to start. Only the exact string `false` enables deletion |

Then add a **daily time-driven trigger** on `pruneBackups`.

## Going live

**Run the first week in dry run, and have a human read the log.** That is
not ceremony: a dry-run log is the only chance to notice a rule doing
something you did not intend before it does it irreversibly.

1. Leave `DRY_RUN` unset (or `true`). Let the daily trigger run for a
   week.
2. Read `retention-log/retention-YYYY-MM.log` in the Shared Drive. Every
   line names the file, its age, and the rule applied. Confirm every
   `WOULD TRASH` is something you actually want gone.
3. Only then set `DRY_RUN` to `false`.

You can preview at any time without changing the property — run
`previewOnly()` from the editor. It forces a dry run and restores the
property afterwards.

If the first live run aborts on the cap, that is correct: enabling this
against a long backlog would trash hundreds of files in one go. Clear the
backlog by hand, then let the daily run keep up with it.

## Tests

Apps Script cannot run locally, so the logic is exercised against a fake
Drive. No dependencies, no framework:

```
node baja-infra/gas/retention/test/prune.test.js
```

Covers all four rules, both floors, the cap, dry-run-by-default, the
"anything but the literal string `false` is a dry run" property, that an
abort sends no heartbeat, and that folders which are not run ids are left
strictly alone. Run it after any change to `Code.js`.

## Second copy — TrueNAS

The pruner is not the last line of defence, because a Manager can empty
the Shared Drive's trash. A cron job on TrueNAS pulls the Shared Drive
into a ZFS dataset via rclone, with ZFS snapshots: **Drive has no WORM
primitive, so the ZFS snapshots are the retention floor.** The pull also
doubles as proof the artifacts are readable from somewhere that is not the
VPS.

Uptime Kuma runs on TrueNAS and therefore cannot alert that TrueNAS is
down. Put an **external dead-man's switch** on that one check.
