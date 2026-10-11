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

/** @param {Array} entries LocalStore.allEntries() */
export function buildEntriesCsv(entries) {
    const lines = [csvLine([
        'uuid', 'carro', 'horario_dispositivo_ms', 'horario_dispositivo_utc', 'desvio_relogio_ms',
        'digitacao_ms', 'modo', 'enviado', 'anulado', 'anulacao_enviada',
    ])];
    for (const entry of entries) {
        lines.push(csvLine([
            entry.uuid, entry.car, entry.ts_device_ms, isoTime(entry.ts_device_ms), entry.clock_offset_ms,
            entry.typing_ms, entry.mode, yesOrNo(entry.sent), yesOrNo(entry.voided), yesOrNo(entry.void_sent),
        ]));
    }
    return BYTE_ORDER_MARK + lines.join(LINE_END) + LINE_END;
}

/** @param {Array} pitEvents LocalStore.allPitEvents() */
export function buildPitEventsCsv(pitEvents) {
    const lines = [csvLine([
        'uuid', 'carro', 'tipo', 'horario_dispositivo_ms', 'horario_dispositivo_utc', 'desvio_relogio_ms', 'enviado',
    ])];
    for (const pitEvent of pitEvents) {
        lines.push(csvLine([
            pitEvent.uuid, pitEvent.car, pitEvent.tipo, pitEvent.ts_device_ms, isoTime(pitEvent.ts_device_ms),
            pitEvent.clock_offset_ms, yesOrNo(pitEvent.sent),
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
