<?php

namespace Baja\Premiacao;

use Baja\Model\Premiacao;

class Renderer
{
    public static function renderCategoryTables(array $categorias, $variant = 'screen')
    {
        if (!count($categorias)) {
            return ($variant === 'pdf')
                ? self::renderPdfEmptyState()
                : self::renderScreenEmptyState();
        }

        $html = '';
        foreach ($categorias as $categoria) {
            $html .= self::renderCategoryTable($categoria, $variant);
        }

        return $html;
    }

    public static function renderPdfDocument($eventoNome, Premiacao $premiacao, array $categorias)
    {
        $status = $premiacao->getStatus() ? 'Ativa' : 'Inativa';
        $statusClass = $premiacao->getStatus() ? 'badge-active' : 'badge-inactive';
        $modificado = $premiacao->getModificado();
        $modStr = $modificado ? $modificado->format('d/m/Y H:i') : '';

        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <style>
        @page { margin: 18px 22px 30px 22px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2933; }
        h1 { margin: 0; font-size: 18px; color: #143b63; }
        .document-header { margin-bottom: 14px; padding: 12px 14px; background: #eef4fb; border: 1px solid #c9d8e8; }
        .document-subtitle { margin: 3px 0 0; font-size: 11px; color: #48627d; }
        .meta { margin-top: 10px; }
        .meta table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 5px 8px; border: 1px solid #d3dee9; background: #ffffff; }
        .meta td.label { width: 18%; background: #e3edf8; font-weight: bold; color: #21486d; }
        .badge { display: inline-block; padding: 2px 8px; font-size: 9px; font-weight: bold; color: #ffffff; }
        .badge-active { background: #2f7d32; }
        .badge-inactive { background: #9a3412; }
        .category { margin-bottom: 14px; page-break-inside: avoid; }
        .category table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .category thead th { border: 1px solid #c7d2df; padding: 6px 7px; word-wrap: break-word; }
        .category thead tr.title th { background: #1f4d7a; color: #ffffff; font-size: 12px; text-align: left; }
        .category thead tr.columns th { background: #e8f0f8; color: #21486d; font-size: 10px; }
        .category tbody td { border: 1px solid #c7d2df; padding: 5px 7px; vertical-align: top; word-wrap: break-word; }
        .category tbody tr:nth-child(even) td { background: #f6f9fc; }
        .state-cell { padding: 8px 9px; font-weight: bold; }
        .state-error { background: #fdecec; color: #a12626; border: 1px solid #efb5b5; }
        .state-empty { background: #fff4e8; color: #9a4d11; border: 1px solid #f0c696; }
        .message { padding: 9px 10px; margin-bottom: 14px; border: 1px solid #f0c696; background: #fff4e8; color: #9a4d11; font-weight: bold; }
        .footer { margin-top: 14px; font-size: 8px; color: #718096; text-align: right; }
    </style>
</head>
<body>
    <div class="document-header">
        <h1>' . self::escape($premiacao->getNome()) . '</h1>
        <div class="document-subtitle">Relatorio de Premiacao</div>
        <div class="meta">
            <table>
                <tr>
                    <td class="label">Evento</td>
                    <td>' . self::escape($eventoNome) . '</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td><span class="badge ' . $statusClass . '">' . self::escape($status) . '</span></td>
                </tr>
                <tr>
                    <td class="label">Atualizacao</td>
                    <td>' . self::escape($modStr) . '</td>
                </tr>
            </table>
        </div>
    </div>
    ' . self::renderCategoryTables($categorias, 'pdf') . '
    <div class="footer">Documento gerado a partir do modulo de premiacoes.</div>
</body>
</html>';
    }

    private static function renderCategoryTable(array $categoria, $variant)
    {
        $headers = isset($categoria['headers']) && is_array($categoria['headers']) ? $categoria['headers'] : array();
        $rows = isset($categoria['rows']) && is_array($categoria['rows']) ? $categoria['rows'] : array();
        $error = isset($categoria['error']) ? $categoria['error'] : null;
        $title = isset($categoria['config']['titulo']) ? $categoria['config']['titulo'] : 'Categoria';
        $colspan = count($headers) ? count($headers) : 1;

        if ($variant === 'pdf') {
            $html = '<div class="category"><table>';
            $html .= self::buildPdfColgroup($headers);
            $html .= '<thead><tr class="title"><th colspan="' . $colspan . '">' . self::escape($title) . '</th></tr>';
            if (count($headers) && empty($error)) {
                $html .= '<tr class="columns">';
                foreach ($headers as $header) {
                    $html .= '<th>' . self::escape($header) . '</th>';
                }
                $html .= '</tr>';
            }
            $html .= '</thead><tbody>';
            $html .= self::renderTableBody($headers, $rows, $error, $colspan, true);
            $html .= '</tbody></table></div>';

            return $html;
        }

        $html = '<table class="tablesorter" style="margin-bottom: 18px;">';
        $html .= '<thead><tr style="height: 50px;"><th colspan="' . $colspan . '" class="sorter-false" style="font-size: 20px; line-height: 24px;">' . self::escape($title) . '</th></tr>';
        if (count($headers) && empty($error)) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $html .= '<th>' . self::escape($header) . '</th>';
            }
            $html .= '</tr>';
        }
        $html .= '</thead><tbody>';
        $html .= self::renderTableBody($headers, $rows, $error, $colspan, false);
        $html .= '</tbody></table>';

        return $html;
    }

    private static function renderTableBody(array $headers, array $rows, $error, $colspan, $pdfMode)
    {
        if (!empty($error)) {
            if ($pdfMode) {
                return '<tr><td colspan="' . $colspan . '" class="state-cell state-error">' . self::escape($error) . '</td></tr>';
            }

            return '<tr><td colspan="' . $colspan . '" style="color: #b30000;">' . self::escape($error) . '</td></tr>';
        }

        if (!count($rows)) {
            if ($pdfMode) {
                return '<tr><td colspan="' . $colspan . '" class="state-cell state-empty">Nenhum dado disponivel para esta categoria.</td></tr>';
            }

            return '<tr><td colspan="' . $colspan . '">Nenhum dado disponivel para esta categoria.</td></tr>';
        }

        $html = '';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($headers as $header) {
                $value = isset($row[$header]) ? $row[$header] : '';
                $html .= '<td>' . self::renderValue($value) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html;
    }

    private static function renderScreenEmptyState()
    {
        return '<table class="tablesorter">
            <thead>
            <tr style="height: 50px;">
                <th class="sorter-false">Categorias</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>Nenhuma categoria configurada para esta premiacao.</td>
            </tr>
            </tbody>
        </table>';
    }

    private static function renderPdfEmptyState()
    {
        return '<div class="message">Nenhuma categoria configurada para esta premiacao.</div>';
    }

    private static function buildPdfColgroup(array $headers)
    {
        if (!count($headers)) {
            return '';
        }

        $weights = array();
        $totalWeight = 0.0;
        foreach ($headers as $header) {
            $weight = self::getPdfColumnWeight($header);
            $weights[] = $weight;
            $totalWeight += $weight;
        }

        if ($totalWeight <= 0) {
            return '';
        }

        $html = '<colgroup>';
        foreach ($weights as $weight) {
            $width = ($weight / $totalWeight) * 100;
            $html .= '<col style="width: ' . number_format($width, 2, '.', '') . '%;" />';
        }
        $html .= '</colgroup>';

        return $html;
    }

    private static function getPdfColumnWeight($header)
    {
        switch ($header) {
            case 'Pos':
                return 7;
            case '#':
                return 7;
            case 'UF':
                return 7;
            case 'Equipe':
                return 24;
            case 'Escola':
                return 22;
            default:
                return 14;
        }
    }

    private static function renderValue($value)
    {
        if (!is_scalar($value)) {
            return '';
        }

        return (string)$value;
    }

    private static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
