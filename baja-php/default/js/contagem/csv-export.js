// "Exportar meus dados": the complete local log as a CSV file.
//
// This is the counter's safety net. It works with no network, with a revoked
// token and with a server that is down, and it contains EVERYTHING recorded on
// this device: sent or not, voided or not.

// Semicolons and a byte-order mark: that is what Excel in Brazilian Portuguese
// opens correctly with a double click.
const SEPARATOR = ';';
const BYTE_ORDER_MARK = '﻿';
const LINE_END = '\r\n';

function csvCell(value) {
    if (value === null || value === undefined) {
        return '';
    }
    const text = String(value);
    if (/[";\r\n]/.test(text)) {
        return '"' + text.replace(/"/g, '""') + '"';
    }
    return text;
}

function csvLine(values) {
    return values.map(csvCell).join(SEPARATOR);
}

function yesOrNo(flag) {
    return flag ? 'sim' : 'nao';
}

function isoTime(timestampMs) {
    return Number.isFinite(timestampMs) ? new Date(timestampMs).toISOString() : '';
}

// A record typed before this page knew the server's time has no official
// time yet (see server-clock.js). The export says so instead of leaving a gap
// that looks like a mistake.
const SEM_HORARIO_OFICIAL = 'aguardando';

/** The time columns shared by both files: official time first, the phone's own clock as a witness. */
function timeCells(record) {
    const hasOfficialTime = Number.isFinite(record.ts_ms);
    return [
        hasOfficialTime ? record.ts_ms : '',
        isoTime(record.ts_ms),
        hasOfficialTime ? record.time_quality : SEM_HORARIO_OFICIAL,
        record.ts_device_ms,
        isoTime(record.ts_device_ms),
    ];
}

const TIME_HEADERS = [
    'horario_oficial_ms', 'horario_oficial_utc', 'qualidade_horario',
    'horario_dispositivo_ms', 'horario_dispositivo_utc',
];

/** @param {Array} entries LocalStore.allEntries() */
export function buildEntriesCsv(entries) {
    const lines = [csvLine([
        'uuid', 'carro', ...TIME_HEADERS, 'digitacao_ms', 'modo', 'enviado', 'anulado', 'anulacao_enviada',
        'recusado_pelo_servidor',
    ])];
    for (const entry of entries) {
        lines.push(csvLine([
            entry.uuid, entry.car, ...timeCells(entry), entry.typing_ms, entry.mode,
            yesOrNo(entry.sent), yesOrNo(entry.voided), yesOrNo(entry.void_sent),
            // Empty for an ordinary entry; otherwise the reason.
            entry.refused ? entry.refused_reason : '',
        ]));
    }
    return BYTE_ORDER_MARK + lines.join(LINE_END) + LINE_END;
}

/** @param {Array} pitEvents LocalStore.allPitEvents() */
export function buildPitEventsCsv(pitEvents) {
    const lines = [csvLine(['uuid', 'carro', 'tipo', ...TIME_HEADERS, 'enviado', 'recusado_pelo_servidor'])];
    for (const pitEvent of pitEvents) {
        lines.push(csvLine([
            pitEvent.uuid, pitEvent.car, pitEvent.tipo, ...timeCells(pitEvent), yesOrNo(pitEvent.sent),
            pitEvent.refused ? pitEvent.refused_reason : '',
        ]));
    }
    return BYTE_ORDER_MARK + lines.join(LINE_END) + LINE_END;
}

/** "Contagem nº 1" -> "contagem-n-1": accents dropped, the rest reduced to letters, digits and dashes. */
function slugOf(part) {
    const withoutAccents = String(part).normalize('NFD').replace(/[̀-ͯ]/g, '');
    return withoutAccents.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase();
}

/** "contagem-26br-end-contagem-1-2026-10-11T14-03-22.csv" */
export function exportFileName(parts, nowMs) {
    const stamp = new Date(nowMs).toISOString().slice(0, 19).replace(/:/g, '-');
    const slug = parts
        .map((part) => slugOf(part))
        .filter((part) => part !== '')
        .join('-');
    return 'contagem-' + slug + '-' + stamp + '.csv';
}

/** Browser glue: hands the text to the user as a downloaded file. */
export function downloadCsv(documentRef, fileName, csvText) {
    const blob = new Blob([csvText], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = documentRef.createElement('a');
    link.href = url;
    link.download = fileName;
    documentRef.body.appendChild(link);
    link.click();
    link.remove();
    // Revoked a little later: some mobile browsers start the download late.
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}
