// Makes `node --test baja-php/tests/js/` work.
//
// Node 22 and later no longer search a directory given to `--test`; they try
// to run it as a program, and a directory "runs" by loading its index.js. So
// this file simply loads every *.test.js next to it. New test files are picked
// up without touching this one.
//
// The other way to run the same tests, one process per file, is:
//     node --test "baja-php/tests/js/*.test.js"

import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const testFolder = dirname(fileURLToPath(import.meta.url));
const testFiles = readdirSync(testFolder).filter((fileName) => fileName.endsWith('.test.js')).sort();

for (const fileName of testFiles) {
    await import(pathToFileURL(join(testFolder, fileName)).href);
}
