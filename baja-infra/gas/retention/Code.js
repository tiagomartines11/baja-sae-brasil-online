/**
 * Retention pruner for the `baja-backups` Google Shared Drive.
 *
 * THIS IS THE MOST DANGEROUS CODE IN THE BACKUP SYSTEM. Everything else
 * can only fail to help; this can actively destroy. The guardrails below
 * are requirements, not suggestions, and every one of them exists because
 * the failure it prevents is unrecoverable.
 *
 *   TRASH, NEVER DELETE      Drive's 30-day trash is a second net. The
 *                            retention maths is honest about it: "expires
 *                            at 35 days" means actually gone at about 65.
 *   FLOOR PER TIER           Never reduce daily/ below 7 run folders or
 *                            monthly/ below 6. A rule that would breach a
 *                            floor aborts the WHOLE run — a bug that
 *                            deletes too much must not be able to finish.
 *   HARD CAP PER RUN         At most MAX_FILES_PER_RUN files. A runaway is
 *                            a bug; this makes it a small one.
 *   DRY RUN BY DEFAULT       Real deletion requires the DRY_RUN script
 *                            property to be exactly the string "false".
 *                            Anything else, including it being unset,
 *                            means dry run.
 *   PLAN, THEN EXECUTE       Nothing is trashed until the whole plan has
 *                            been built and every guardrail checked
 *                            against it. Deleting as you walk means a
 *                            floor breach is discovered after the damage.
 *   NEVER TOUCH yearly/      Manual review only.
 *   NEVER TRASH A MANIFEST   Except as part of removing its whole folder.
 *                            It is the record of what a run contained.
 *
 * Runs under a DEDICATED ROLE ACCOUNT, never a person's. Apps Script
 * triggers execute as whoever installed them, and a trigger bound to a
 * student who graduates is precisely the failure mode this whole work
 * package exists to prevent.
 *
 * Retention tiers:
 *
 *                        daily/     monthly/    yearly/
 *   baja_resultados      35 days    24 months   indefinite
 *   phpbb_baja/_formula  35 days     6 months   not written
 *
 * baja_resultados keeps the long tail because certificates are generated
 * on demand — the database IS the certificate archive — and a scoring
 * correction discovered years later is a real scenario. That
 * justification does not extend to forum history, and holding personal
 * data past its purpose is an Art. 15/16 liability rather than mere
 * waste.
 *
 * See baja-infra/docs/backup-restore.md and this directory's README.md.
 */

// ---------------------------------------------------------------------
// Configuration (Script Properties — File > Project properties)
// ---------------------------------------------------------------------

var DAILY_MAX_AGE_DAYS = 35;
var MONTHLY_PHPBB_MAX_AGE_MONTHS = 6;
var MONTHLY_MAX_AGE_MONTHS = 24;

var DAILY_FLOOR_RUNS = 7;
var MONTHLY_FLOOR_RUNS = 6;

var MAX_FILES_PER_RUN = 30;

var LOG_FOLDER_NAME = 'retention-log';
// Above this, the month's log is left alone and a new one is started
// rather than reading and rewriting an ever-growing file on every run.
var LOG_MAX_BYTES = 512 * 1024;

var PHPBB_DATABASES = ['phpbb_baja', 'phpbb_formula'];
var MANIFEST_NAME = 'manifest.json';

/**
 * Trigger entry point. Install a DAILY time-driven trigger on this.
 */
function pruneBackups() {
  var started = new Date();
  var ctx = {
    now: new Date(),
    dryRun: isDryRun(),
    plan: [],          // {file|folder, name, path, rule, ageText, fileCount}
    lines: [],         // log lines
    aborted: null,
    logged: false
  };

  ctx.lines.push('=== run ' + started.toISOString() +
                 ' (' + (ctx.dryRun ? 'DRY RUN' : 'LIVE') + ') ===');

  try {
    var root = getSharedDriveRoot();

    planDaily(root, ctx);
    planMonthly(root, ctx);
    noteYearly(root, ctx);

    enforceGuardrails(ctx);

    if (ctx.aborted) {
      ctx.lines.push('ABORTED: ' + ctx.aborted);
      writeLog(ctx);
      // No Kuma heartbeat: an abort is a condition a human must look at.
      throw new Error(ctx.aborted);
    }

    execute(ctx);
    writeLog(ctx);

    if (!ctx.dryRun) {
      pushKuma('pruned ' + countPlannedFiles(ctx) + ' file(s) in ' +
               ctx.plan.length + ' action(s)');
    } else {
      ctx.lines.push('DRY RUN — nothing was trashed. Set the DRY_RUN ' +
                     'script property to "false" to enable deletion.');
    }
  } catch (e) {
    // An abort has already written its own log; do not write it twice.
    if (!ctx.logged) {
      ctx.lines.push('ERROR: ' + (e && e.message ? e.message : e));
      try { writeLog(ctx); } catch (ignored) {}
    }
    // Rethrown so the failure shows in the Apps Script execution log AND
    // so no heartbeat is sent. Kuma alerts on the silence.
    throw e;
  }
}

// ---------------------------------------------------------------------
// Planning
// ---------------------------------------------------------------------

/** Rule 1: daily/<run_id>/ older than 35 days -> trash the whole folder. */
function planDaily(root, ctx) {
  var tier = getChildFolder(root, 'daily');
  if (!tier) { ctx.lines.push('daily/ not present — nothing to do'); return; }

  var runs = listRunFolders(tier, ctx);
  ctx.dailyTotal = runs.length;

  for (var i = 0; i < runs.length; i++) {
    var age = daysBetween(runs[i].date, ctx.now);
    if (age > DAILY_MAX_AGE_DAYS) {
      ctx.plan.push({
        folder: runs[i].folder,
        name: runs[i].name,
        path: 'daily/' + runs[i].name,
        rule: 'R1 daily older than ' + DAILY_MAX_AGE_DAYS + 'd',
        ageText: age + 'd',
        fileCount: countFiles(runs[i].folder)
      });
    }
  }
}

/**
 * Rules 2 and 3, in that precedence: a folder old enough for rule 3 is
 * removed entirely, so it is never also considered for rule 2.
 */
function planMonthly(root, ctx) {
  var tier = getChildFolder(root, 'monthly');
  if (!tier) { ctx.lines.push('monthly/ not present — nothing to do'); return; }

  var runs = listRunFolders(tier, ctx);
  ctx.monthlyTotal = runs.length;

  for (var i = 0; i < runs.length; i++) {
    var run = runs[i];
    var months = monthsBetween(run.date, ctx.now);

    // Rule 3: the whole folder goes.
    if (months >= MONTHLY_MAX_AGE_MONTHS) {
      ctx.plan.push({
        folder: run.folder,
        name: run.name,
        path: 'monthly/' + run.name,
        rule: 'R3 monthly older than ' + MONTHLY_MAX_AGE_MONTHS + 'mo',
        ageText: months + 'mo',
        fileCount: countFiles(run.folder)
      });
      continue;
    }

    // Rule 2: drop the forum artifacts, keep baja_resultados and the
    // manifest. The manifest stays even though it now describes files
    // that are gone — it is the record of what the run contained, and a
    // folder without one cannot be interpreted.
    if (months >= MONTHLY_PHPBB_MAX_AGE_MONTHS) {
      var victims = phpbbArtifactsIn(run.folder);
      for (var j = 0; j < victims.length; j++) {
        ctx.plan.push({
          file: victims[j],
          name: victims[j].getName(),
          path: 'monthly/' + run.name + '/' + victims[j].getName(),
          rule: 'R2 phpbb artifact older than ' + MONTHLY_PHPBB_MAX_AGE_MONTHS + 'mo',
          ageText: months + 'mo',
          fileCount: 1
        });
      }
    }
  }
}

/** Rule 4: yearly/ is never touched. Counted only so the log says so. */
function noteYearly(root, ctx) {
  var tier = getChildFolder(root, 'yearly');
  var count = tier ? listRunFolders(tier, ctx).length : 0;
  ctx.lines.push('yearly/: ' + count + ' run(s) — NOT TOUCHED (rule 4, manual review only)');
}

/**
 * Returns the artifacts and sidecars belonging to the phpBB databases.
 *
 * Matched by explicit prefix, never by "everything that is not
 * baja_resultados". A file this function does not recognise is left
 * alone, which is the correct bias for code that deletes.
 */
function phpbbArtifactsIn(folder) {
  var out = [];
  var it = folder.getFiles();
  while (it.hasNext()) {
    var file = it.next();
    var name = file.getName();
    if (name === MANIFEST_NAME) { continue; }   // never, except with its folder
    for (var i = 0; i < PHPBB_DATABASES.length; i++) {
      var db = PHPBB_DATABASES[i];
      if (name === db + '.sql.gz.age' || name === db + '.sql.gz.age.sha256') {
        out.push(file);
        break;
      }
    }
  }
  return out;
}

// ---------------------------------------------------------------------
// Guardrails
// ---------------------------------------------------------------------

function enforceGuardrails(ctx) {
  // Floors. Counted against folder removals only — rule 2 thins a folder
  // but does not remove it, so it cannot breach a run-count floor.
  var dailyRemovals = countFolderRemovals(ctx, 'daily/');
  var monthlyRemovals = countFolderRemovals(ctx, 'monthly/');

  var dailyLeft = (ctx.dailyTotal || 0) - dailyRemovals;
  var monthlyLeft = (ctx.monthlyTotal || 0) - monthlyRemovals;

  if (dailyRemovals > 0 && dailyLeft < DAILY_FLOOR_RUNS) {
    ctx.aborted = 'refusing to run: daily/ would drop to ' + dailyLeft +
                  ' run(s), floor is ' + DAILY_FLOOR_RUNS +
                  '. Either the backup job has stopped producing runs, or ' +
                  'this script has a bug. Investigate before pruning again.';
    return;
  }
  if (monthlyRemovals > 0 && monthlyLeft < MONTHLY_FLOOR_RUNS) {
    ctx.aborted = 'refusing to run: monthly/ would drop to ' + monthlyLeft +
                  ' run(s), floor is ' + MONTHLY_FLOOR_RUNS + '.';
    return;
  }

  // Hard cap. Counted in FILES, including the contents of folders that
  // would be trashed, because that is what is actually at risk.
  var files = countPlannedFiles(ctx);
  if (files > MAX_FILES_PER_RUN) {
    ctx.aborted = 'refusing to run: the plan touches ' + files +
                  ' files, cap is ' + MAX_FILES_PER_RUN +
                  '. A backlog this large is not something to clear ' +
                  'automatically — review the log and prune by hand.';
    return;
  }
}

function countFolderRemovals(ctx, pathPrefix) {
  var n = 0;
  for (var i = 0; i < ctx.plan.length; i++) {
    if (ctx.plan[i].folder && ctx.plan[i].path.indexOf(pathPrefix) === 0) { n++; }
  }
  return n;
}

function countPlannedFiles(ctx) {
  var n = 0;
  for (var i = 0; i < ctx.plan.length; i++) { n += ctx.plan[i].fileCount; }
  return n;
}

// ---------------------------------------------------------------------
// Execution
// ---------------------------------------------------------------------

function execute(ctx) {
  if (ctx.plan.length === 0) {
    ctx.lines.push('nothing to prune');
    return;
  }

  for (var i = 0; i < ctx.plan.length; i++) {
    var action = ctx.plan[i];
    var verb = ctx.dryRun ? 'WOULD TRASH' : 'TRASHED';
    var what = action.folder ? 'folder' : 'file';

    if (!ctx.dryRun) {
      // setTrashed, never a permanent delete: Drive's 30-day trash is
      // the second net the retention maths already accounts for.
      (action.folder || action.file).setTrashed(true);
    }

    ctx.lines.push(verb + ' ' + what + ' ' + action.path +
                   ' | age ' + action.ageText +
                   ' | files ' + action.fileCount +
                   ' | ' + action.rule);
  }

  ctx.lines.push('total: ' + ctx.plan.length + ' action(s), ' +
                 countPlannedFiles(ctx) + ' file(s)');
}

// ---------------------------------------------------------------------
// Drive helpers
// ---------------------------------------------------------------------

function getSharedDriveRoot() {
  var id = getRequiredProperty('SHARED_DRIVE_ID');
  try {
    return DriveApp.getFolderById(id);
  } catch (e) {
    throw new Error('cannot open Shared Drive ' + id + ' — check ' +
                    'SHARED_DRIVE_ID, and that this account is a Manager ' +
                    'on the drive. (' + e + ')');
  }
}

function getChildFolder(parent, name) {
  var it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : null;
}

/**
 * Run folders, oldest first, keyed on the RUN ID rather than on Drive's
 * created date. The run id is what the artifact means; Drive's timestamp
 * is when the upload happened, and a re-upload or a copy would reset it.
 */
function listRunFolders(tier, ctx) {
  var out = [];
  var it = tier.getFolders();
  while (it.hasNext()) {
    var folder = it.next();
    var name = folder.getName();
    var date = parseRunId(name);
    if (!date) {
      // Not a run folder. Left strictly alone — this is where someone's
      // manually-copied "20260817T040000Z - keep me" would live.
      ctx.lines.push('skipping unrecognised folder: ' + tier.getName() + '/' + name);
      continue;
    }
    out.push({ folder: folder, name: name, date: date });
  }
  out.sort(function (a, b) { return a.date - b.date; });
  return out;
}

function countFiles(folder) {
  var n = 0;
  var it = folder.getFiles();
  while (it.hasNext()) { it.next(); n++; }
  return n;
}

/** '20260817T040000Z' -> Date, or null if the name is not a run id. */
function parseRunId(name) {
  var m = /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/.exec(name);
  if (!m) { return null; }
  return new Date(Date.UTC(+m[1], (+m[2]) - 1, +m[3], +m[4], +m[5], +m[6]));
}

function daysBetween(from, to) {
  return Math.floor((to.getTime() - from.getTime()) / 86400000);
}

/**
 * Whole calendar months elapsed. Not days/30: "6 months" has to mean the
 * same thing in February as in July, and a day-based approximation drifts
 * by days per year against a rule people reason about in months.
 */
function monthsBetween(from, to) {
  var months = (to.getUTCFullYear() - from.getUTCFullYear()) * 12 +
               (to.getUTCMonth() - from.getUTCMonth());
  if (to.getUTCDate() < from.getUTCDate()) { months -= 1; }
  return months;
}

// ---------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------

/**
 * Every action is written to a log file in the Shared Drive itself —
 * file name, age, rule applied — so the record survives the Apps Script
 * execution log's retention and is readable by anyone with the Drive.
 * One file per month.
 */
function writeLog(ctx) {
  var body = ctx.lines.join('\n') + '\n';
  ctx.logged = true;
  Logger.log(body);

  try {
    var root = getSharedDriveRoot();
    var folder = getChildFolder(root, LOG_FOLDER_NAME) ||
                 root.createFolder(LOG_FOLDER_NAME);

    var stamp = Utilities.formatDate(ctx.now, 'UTC', 'yyyy-MM');
    var name = 'retention-' + stamp + '.log';
    var it = folder.getFilesByName(name);

    if (it.hasNext()) {
      var file = it.next();
      if (file.getSize() > LOG_MAX_BYTES) {
        folder.createFile(name.replace('.log', '') + '-' +
                          Utilities.formatDate(ctx.now, 'UTC', 'ddHHmmss') + '.log', body);
      } else {
        file.setContent(file.getBlob().getDataAsString() + body);
      }
    } else {
      folder.createFile(name, body);
    }
  } catch (e) {
    // A failure to log must not become a failure to prune correctly, nor
    // hide the original error. It is recorded in the execution log and
    // the run continues.
    Logger.log('could not write the Drive log: ' + e);
  }
}

// ---------------------------------------------------------------------
// Monitoring
// ---------------------------------------------------------------------

/**
 * Pinged only on a successful LIVE run. Same contract as the backup and
 * verify jobs: nothing here reports its own failure, and Kuma alerts on
 * the missing heartbeat.
 */
function pushKuma(message) {
  var url = PropertiesService.getScriptProperties().getProperty('KUMA_PUSH_URL_PRUNE');
  if (!url) {
    Logger.log('no KUMA_PUSH_URL_PRUNE configured — success will not be reported');
    return;
  }
  try {
    UrlFetchApp.fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') +
                      'status=up&msg=' + encodeURIComponent(message),
                      { muteHttpExceptions: true });
  } catch (e) {
    Logger.log('Kuma push failed (the prune itself succeeded): ' + e);
  }
}

// ---------------------------------------------------------------------
// Properties
// ---------------------------------------------------------------------

/**
 * Dry run unless the property is EXACTLY the string "false". Unset,
 * empty, misspelled, or any other value all mean dry run — the default
 * has to be the safe one for every way of getting it wrong.
 */
function isDryRun() {
  var value = PropertiesService.getScriptProperties().getProperty('DRY_RUN');
  return value !== 'false';
}

function getRequiredProperty(key) {
  var value = PropertiesService.getScriptProperties().getProperty(key);
  if (!value) {
    throw new Error('script property ' + key + ' is not set — see ' +
                    'baja-infra/gas/retention/README.md');
  }
  return value;
}

/**
 * Convenience for the console: prints the plan and changes nothing,
 * regardless of the DRY_RUN property. Use this to review before going
 * live, and any time the log looks surprising.
 */
function previewOnly() {
  var props = PropertiesService.getScriptProperties();
  var saved = props.getProperty('DRY_RUN');
  props.setProperty('DRY_RUN', 'true');
  try {
    pruneBackups();
  } finally {
    if (saved === null) { props.deleteProperty('DRY_RUN'); }
    else { props.setProperty('DRY_RUN', saved); }
  }
}
