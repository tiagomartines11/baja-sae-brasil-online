/**
 * Tests for the retention pruner.
 *
 * Apps Script cannot be run locally, and the pruner is the one piece of
 * this system that can actively destroy data — so its logic is exercised
 * here against a fake Drive instead of being reasoned about and hoped
 * for. This covers acceptance criterion 11: all four rules, the per-tier
 * floors, the per-run cap, and dry-run-by-default.
 *
 * No dependencies and no test framework, because baja-infra has neither:
 *
 *     node baja-infra/gas/retention/test/prune.test.js
 *
 * Exits non-zero on the first failure.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const CODE_PATH = path.join(__dirname, '..', 'Code.js');

// ---------------------------------------------------------------------
// A fake Drive, just rich enough for the pruner's API surface.
// ---------------------------------------------------------------------

function makeFile(name, content) {
  return {
    _kind: 'file',
    _name: name,
    _content: content === undefined ? 'x' : content,
    trashed: false,
    getName() { return this._name; },
    getSize() { return this._content.length; },
    getBlob() { const self = this; return { getDataAsString() { return self._content; } }; },
    setContent(c) { this._content = c; },
    setTrashed(v) { this.trashed = v; }
  };
}

function makeFolder(name) {
  return {
    _kind: 'folder',
    _name: name,
    folders: [],
    files: [],
    trashed: false,
    getName() { return this._name; },
    setTrashed(v) { this.trashed = v; },
    getFolders() { return iter(this.folders); },
    getFiles() { return iter(this.files); },
    getFoldersByName(n) { return iter(this.folders.filter(f => f._name === n)); },
    getFilesByName(n) { return iter(this.files.filter(f => f._name === n)); },
    createFolder(n) { const f = makeFolder(n); this.folders.push(f); return f; },
    createFile(n, c) { const f = makeFile(n, c); this.files.push(f); return f; }
  };
}

function iter(items) {
  let i = 0;
  return { hasNext: () => i < items.length, next: () => items[i++] };
}

/** A run folder holding the standard artifact set. */
function runFolder(runId, opts) {
  opts = opts || {};
  const folder = makeFolder(runId);
  const dbs = opts.databases || ['baja_resultados', 'phpbb_baja', 'phpbb_formula'];
  dbs.forEach(db => {
    folder.createFile(db + '.sql.gz.age');
    folder.createFile(db + '.sql.gz.age.sha256');
  });
  if (opts.manifest !== false) { folder.createFile('manifest.json'); }
  return folder;
}

/** run id N days before the fixed NOW. */
const NOW = new Date(Date.UTC(2026, 7, 17, 4, 0, 0));   // 2026-08-17T04:00:00Z

function runIdDaysAgo(days) {
  return toRunId(new Date(NOW.getTime() - days * 86400000));
}
function runIdMonthsAgo(months) {
  const d = new Date(NOW.getTime());
  d.setUTCMonth(d.getUTCMonth() - months);
  return toRunId(d);
}
function toRunId(d) {
  const p = n => String(n).padStart(2, '0');
  return `${d.getUTCFullYear()}${p(d.getUTCMonth() + 1)}${p(d.getUTCDate())}T` +
         `${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}Z`;
}

// ---------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------

const SOURCE = fs.readFileSync(CODE_PATH, 'utf8');

function run(build, properties) {
  const root = makeFolder('baja-backups');
  build(root);

  const props = Object.assign({ SHARED_DRIVE_ID: 'drive-id' }, properties || {});
  const logs = [];
  const fetched = [];

  // A Date whose no-arg form is pinned, so "35 days old" means the same
  // thing on every machine and in every month.
  class FixedDate extends Date {
    constructor(...args) {
      if (args.length === 0) { super(NOW.getTime()); } else { super(...args); }
    }
    static UTC(...args) { return Date.UTC(...args); }
    static now() { return NOW.getTime(); }
  }

  const sandbox = {
    Date: FixedDate,
    DriveApp: {
      getFolderById(id) {
        if (id !== 'drive-id') { throw new Error('no such folder: ' + id); }
        return root;
      }
    },
    PropertiesService: {
      getScriptProperties: () => ({
        getProperty: k => (k in props ? props[k] : null),
        setProperty: (k, v) => { props[k] = v; },
        deleteProperty: k => { delete props[k]; }
      })
    },
    Logger: { log: m => logs.push(String(m)) },
    Utilities: {
      formatDate(d, tz, fmt) {
        const p = n => String(n).padStart(2, '0');
        if (fmt === 'yyyy-MM') { return `${d.getUTCFullYear()}-${p(d.getUTCMonth() + 1)}`; }
        return `${p(d.getUTCDate())}${p(d.getUTCHours())}${p(d.getUTCMinutes())}${p(d.getUTCSeconds())}`;
      }
    },
    UrlFetchApp: { fetch: (url) => { fetched.push(url); return {}; } }
  };

  vm.createContext(sandbox);
  vm.runInContext(SOURCE, sandbox);

  let error = null;
  try { sandbox.pruneBackups(); } catch (e) { error = e; }

  return { root, logs: logs.join('\n'), error, fetched };
}

/** Every trashed path in the tree, as "tier/run" or "tier/run/file". */
function trashed(root) {
  const out = [];
  root.folders.forEach(tier => {
    if (tier._name === 'retention-log') { return; }
    tier.folders.forEach(runDir => {
      if (runDir.trashed) { out.push(`${tier._name}/${runDir._name}`); return; }
      runDir.files.forEach(f => {
        if (f.trashed) { out.push(`${tier._name}/${runDir._name}/${f._name}`); }
      });
    });
  });
  return out.sort();
}

// ---------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------

let failures = 0;
function check(name, fn) {
  try {
    fn();
    console.log('  ok   ' + name);
  } catch (e) {
    failures++;
    console.log('  FAIL ' + name + '\n         ' + e.message);
  }
}
function eq(actual, expected, what) {
  const a = JSON.stringify(actual), b = JSON.stringify(expected);
  if (a !== b) { throw new Error(`${what || 'value'}:\n           got      ${a}\n           expected ${b}`); }
}
function contains(haystack, needle, what) {
  if (haystack.indexOf(needle) < 0) {
    throw new Error(`${what || 'output'} does not contain ${JSON.stringify(needle)}`);
  }
}

const LIVE = { DRY_RUN: 'false' };

// A daily tier with enough recent runs that the floor is never the thing
// under test. `extra` adds the runs a test actually cares about.
function healthyDaily(root, extra) {
  const daily = root.createFolder('daily');
  for (let i = 0; i < 10; i++) { daily.folders.push(runFolder(runIdDaysAgo(i))); }
  (extra || []).forEach(r => daily.folders.push(r));
  return daily;
}

console.log('\nretention pruner\n');

// ----- Rule 1 -----
check('R1 trashes a daily run older than 35 days', () => {
  const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); }, LIVE);
  eq(r.error, null, 'error');
  eq(trashed(r.root), ['daily/' + runIdDaysAgo(40)], 'trashed');
});

check('R1 leaves a daily run at exactly 35 days alone', () => {
  const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(35))]); }, LIVE);
  eq(trashed(r.root), [], 'trashed');
});

// ----- Rule 2 -----
check('R2 trashes phpbb artifacts from a 7-month-old monthly run, keeping baja_resultados and the manifest', () => {
  const runId = runIdMonthsAgo(7);
  const r = run(root => {
    healthyDaily(root);
    const monthly = root.createFolder('monthly');
    for (let i = 0; i < 6; i++) { monthly.folders.push(runFolder(runIdMonthsAgo(i))); }
    monthly.folders.push(runFolder(runId));
  }, LIVE);
  eq(r.error, null, 'error');
  eq(trashed(r.root), [
    `monthly/${runId}/phpbb_baja.sql.gz.age`,
    `monthly/${runId}/phpbb_baja.sql.gz.age.sha256`,
    `monthly/${runId}/phpbb_formula.sql.gz.age`,
    `monthly/${runId}/phpbb_formula.sql.gz.age.sha256`
  ], 'trashed');
});

check('R2 never trashes the manifest', () => {
  const runId = runIdMonthsAgo(12);
  const r = run(root => {
    healthyDaily(root);
    const monthly = root.createFolder('monthly');
    for (let i = 0; i < 6; i++) { monthly.folders.push(runFolder(runIdMonthsAgo(i))); }
    monthly.folders.push(runFolder(runId));
  }, LIVE);
  const kept = r.root.folders.find(f => f._name === 'monthly')
                .folders.find(f => f._name === runId)
                .files.filter(f => !f.trashed).map(f => f._name).sort();
  eq(kept, ['baja_resultados.sql.gz.age', 'baja_resultados.sql.gz.age.sha256', 'manifest.json'], 'kept files');
});

// ----- Rule 3 -----
check('R3 trashes a 25-month-old monthly run whole, and does not also apply R2 to it', () => {
  const runId = runIdMonthsAgo(25);
  const r = run(root => {
    healthyDaily(root);
    const monthly = root.createFolder('monthly');
    for (let i = 0; i < 6; i++) { monthly.folders.push(runFolder(runIdMonthsAgo(i))); }
    monthly.folders.push(runFolder(runId));
  }, LIVE);
  eq(trashed(r.root), ['monthly/' + runId], 'trashed');
});

// ----- Rule 4 -----
check('R4 never touches yearly/, however old', () => {
  const r = run(root => {
    healthyDaily(root);
    const yearly = root.createFolder('yearly');
    yearly.folders.push(runFolder(runIdMonthsAgo(120), { databases: ['baja_resultados'] }));
  }, LIVE);
  eq(trashed(r.root), [], 'trashed');
  contains(r.logs, 'NOT TOUCHED (rule 4', 'log');
});

// ----- Floors -----
check('aborts rather than dropping daily/ below 7 runs, and trashes nothing', () => {
  const r = run(root => {
    const daily = root.createFolder('daily');
    for (let i = 0; i < 3; i++) { daily.folders.push(runFolder(runIdDaysAgo(i))); }
    for (let i = 0; i < 3; i++) { daily.folders.push(runFolder(runIdDaysAgo(40 + i))); }
  }, LIVE);
  if (!r.error) { throw new Error('expected an abort'); }
  contains(r.error.message, 'floor is 7', 'abort message');
  eq(trashed(r.root), [], 'trashed');
});

check('aborts rather than dropping monthly/ below 6 runs', () => {
  const r = run(root => {
    healthyDaily(root);
    const monthly = root.createFolder('monthly');
    for (let i = 0; i < 2; i++) { monthly.folders.push(runFolder(runIdMonthsAgo(i))); }
    for (let i = 0; i < 3; i++) { monthly.folders.push(runFolder(runIdMonthsAgo(25 + i))); }
  }, LIVE);
  if (!r.error) { throw new Error('expected an abort'); }
  contains(r.error.message, 'floor is 6', 'abort message');
  eq(trashed(r.root), [], 'trashed');
});

// ----- Hard cap -----
check('aborts when the plan exceeds the per-run file cap', () => {
  const r = run(root => {
    const daily = root.createFolder('daily');
    for (let i = 0; i < 10; i++) { daily.folders.push(runFolder(runIdDaysAgo(i))); }
    // 8 old runs x 7 files = 56, well past the cap of 30.
    for (let i = 0; i < 8; i++) { daily.folders.push(runFolder(runIdDaysAgo(40 + i))); }
  }, LIVE);
  if (!r.error) { throw new Error('expected an abort'); }
  contains(r.error.message, 'cap is 30', 'abort message');
  eq(trashed(r.root), [], 'trashed');
});

// ----- Dry run -----
check('dry run by default: plans the work and trashes nothing', () => {
  const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); });  // no DRY_RUN property
  eq(r.error, null, 'error');
  eq(trashed(r.root), [], 'trashed');
  contains(r.logs, 'WOULD TRASH folder daily/' + runIdDaysAgo(40), 'log');
});

check('any DRY_RUN value other than the exact string "false" means dry run', () => {
  ['true', 'False', 'FALSE', '0', 'no', ''].forEach(value => {
    const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); }, { DRY_RUN: value });
    eq(trashed(r.root), [], `trashed with DRY_RUN=${JSON.stringify(value)}`);
  });
});

check('a live run pings Kuma; a dry run does not', () => {
  const build = root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); };
  const live = run(build, { DRY_RUN: 'false', KUMA_PUSH_URL_PRUNE: 'https://kuma.example/push/abc' });
  eq(live.fetched.length, 1, 'live fetch count');
  const dry = run(build, { KUMA_PUSH_URL_PRUNE: 'https://kuma.example/push/abc' });
  eq(dry.fetched.length, 0, 'dry-run fetch count');
});

check('an abort sends no Kuma heartbeat', () => {
  const r = run(root => {
    const daily = root.createFolder('daily');
    for (let i = 0; i < 3; i++) { daily.folders.push(runFolder(runIdDaysAgo(40 + i))); }
  }, { DRY_RUN: 'false', KUMA_PUSH_URL_PRUNE: 'https://kuma.example/push/abc' });
  if (!r.error) { throw new Error('expected an abort'); }
  eq(r.fetched.length, 0, 'fetch count');
});

// ----- Things that are not run folders -----
check('leaves folders whose names are not run ids strictly alone', () => {
  const r = run(root => {
    const daily = healthyDaily(root);
    daily.folders.push(makeFolder('20260101T040000Z - keep me'));
    daily.folders.push(makeFolder('scratch'));
  }, LIVE);
  eq(trashed(r.root), [], 'trashed');
  contains(r.logs, 'skipping unrecognised folder: daily/scratch', 'log');
});

check('logs file name, age and rule for every action', () => {
  const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); }, LIVE);
  contains(r.logs, 'daily/' + runIdDaysAgo(40), 'log path');
  contains(r.logs, 'age 40d', 'log age');
  contains(r.logs, 'R1 daily older than 35d', 'log rule');
});

check('writes the run log into the Shared Drive', () => {
  const r = run(root => { healthyDaily(root, [runFolder(runIdDaysAgo(40))]); }, LIVE);
  const logFolder = r.root.folders.find(f => f._name === 'retention-log');
  if (!logFolder) { throw new Error('no retention-log folder was created'); }
  eq(logFolder.files.map(f => f._name), ['retention-2026-08.log'], 'log files');
});

console.log('');
if (failures > 0) {
  console.log(`${failures} failure(s)\n`);
  process.exit(1);
}
console.log('all checks passed\n');
