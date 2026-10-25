<?php

namespace Baja\Premiacao;

use Baja\Model\EquipeQuery;
use Baja\Model\Premiacao;
use Baja\Model\ResultadoQuery;
use Baja\Results\Column;
use Baja\Results\Ranking\Ranking;
use Baja\Results\ResultRow;
use Baja\Results\ResultsBuilder;
use Baja\Results\ResultsConfig;
use Baja\Results\ViewerContext;

class Resolver
{
    private static $datasetCache = array();

    /**
     * Resolve todas as categorias de uma premiação.
     *
     * @param Premiacao $premiacao
     * @return array
     */
    public static function resolve(Premiacao $premiacao)
    {
        $raw = $premiacao->getCategorias();
        if (!$raw) {
            return array();
        }

        // Converte stdClass para array associativo para processamento interno
        $config = json_decode(json_encode($raw), true);
        if (!isset($config['categorias']) || !is_array($config['categorias'])) {
            return array();
        }

        $resolved = array();
        foreach ($config['categorias'] as $categoria) {
            if (!is_array($categoria)) {
                continue;
            }
            $resolved[] = self::resolveCategoria($premiacao->getEventoId(), $categoria, $config);
        }

        return $resolved;
    }

    /**
     * Resolve uma categoria individual.
     *
     * @param string $eventoId
     * @param array  $categoria
     * @param array  $config
     * @return array
     */
    public static function resolveCategoria($eventoId, array $categoria, array $config = array())
    {
        $modo = isset($categoria['modo']) ? strtolower(trim((string)$categoria['modo'])) : 'result_top';

        if ($modo === 'manual') {
            return self::resolveManualCategory($eventoId, $categoria);
        }

        $resultadoId = isset($categoria['resultado_id']) ? trim((string)$categoria['resultado_id']) : '';
        if ($resultadoId === '') {
            return self::buildError($categoria, 'resultado_id não informado.');
        }

        $dataset = self::getResultDataset($eventoId, $resultadoId);
        if (!empty($dataset['error'])) {
            return self::buildError($categoria, $dataset['error']);
        }

        $rows = self::applyScopeFilters($dataset['rows'], $categoria, $config);
        $rankingHeader       = $dataset['ranking_key'];
        $rankingDirection    = $dataset['direction'];
        $rankingUsesTiebreak = $dataset['uses_tiebreak'];

        if ($modo === 'column_top') {
            $sortHeader = isset($categoria['sort_header']) ? $categoria['sort_header'] : null;
            if (!$sortHeader) {
                return self::buildError($categoria, 'sort_header não informado.');
            }
            if (!in_array($sortHeader, $dataset['headers'], true)) {
                return self::buildError($categoria, 'Cabeçalho não encontrado no resultado: ' . $sortHeader);
            }
            $rankingHeader       = $sortHeader;
            $rankingDirection    = (isset($categoria['direction']) && strtolower(trim((string)$categoria['direction'])) === 'asc') ? 'asc' : 'desc';
            $rankingUsesTiebreak = false;
        }

        // Ranked again: column_top ranks by its own column, and region and
        // novice categories rank only the teams they keep.
        if ($rankingHeader !== null) {
            $rows = Ranking::rank($rows, $rankingHeader, $rankingDirection, $rankingUsesTiebreak);
        }

        $rows = Selection::apply($rows, $categoria);

        $headers = self::resolveHeaders($dataset['headers'], $categoria);
        $displayRows = array();
        foreach ($rows as $row) {
            $row = self::legacyRow($row);
            $displayRow = array();
            foreach ($headers as $header) {
                $displayRow[$header] = self::resolveDisplayValue($row, $header);
            }
            $displayRows[] = $displayRow;
        }

        return array(
            'config'  => $categoria,
            'headers' => $headers,
            'rows'    => $displayRows,
            'error'   => null,
        );
    }

    // ─── Dataset ──────────────────────────────────────────────────────────────

    /**
     * The resultado's rows, ranked by its "pos", from the shared results
     * builder (the same rows as the results page, in position order).
     * Cached per request: several categories usually share one resultado.
     */
    private static function getResultDataset($eventoId, $resultadoId)
    {
        $cacheKey = $eventoId . ':' . $resultadoId;
        if (isset(self::$datasetCache[$cacheKey])) {
            return self::$datasetCache[$cacheKey];
        }

        $resultado = ResultadoQuery::create()
            ->filterByEventoId($eventoId)
            ->findPk($resultadoId);

        if (!$resultado) {
            self::$datasetCache[$cacheKey] = array(
                'headers' => array(), 'rows' => array(),
                'error'   => 'Resultado não encontrado: ' . $resultadoId,
            );
            return self::$datasetCache[$cacheKey];
        }

        $config = $resultado->getColunas();
        if (!is_object($config) || !isset($config->colunas)) {
            self::$datasetCache[$cacheKey] = array(
                'headers' => array(), 'rows' => array(),
                'error'   => 'Configuração de colunas inválida para: ' . $resultadoId,
            );
            return self::$datasetCache[$cacheKey];
        }

        if (@$config->type === 'tournament') {
            self::$datasetCache[$cacheKey] = array(
                'headers' => array(), 'rows' => array(),
                'error'   => 'Resultados de torneio não são suportados nesta tela.',
            );
            return self::$datasetCache[$cacheKey];
        }

        $resultsConfig = ResultsConfig::fromColunas($config);
        $table = ResultsBuilder::buildFor($resultado, $resultsConfig, ViewerContext::premiacao());

        self::$datasetCache[$cacheKey] = array(
            'headers'       => array_map(function (Column $column) { return $column->key(); }, $resultsConfig->columns),
            'rows'          => $table->rows,
            'ranking_key'   => $resultsConfig->rankingKey(),
            'direction'     => $resultsConfig->direction(),
            'uses_tiebreak' => $resultsConfig->tiebreakFormula() !== null,
            'error'         => null,
        );

        return self::$datasetCache[$cacheKey];
    }

    /**
     * A row as the display code below reads it: the team under "__TEAM_*"
     * keys and its position under "__POS", then each column by header.
     *
     * @return array<string|int, mixed>
     */
    private static function legacyRow(ResultRow $row)
    {
        $legacy = array(
            '__TEAM_ID'     => $row->team->teamId,
            '__TEAM_NUM'    => $row->team->number,
            '__TEAM_NAME'   => $row->team->name,
            '__TEAM_SCHOOL' => $row->team->school,
            '__TEAM_STATE'  => $row->team->state,
            '__POS'         => $row->position,
        );
        foreach ($row->cells() as $header => $cell) {
            $legacy[$header] = $cell->display;
        }
        return $legacy;
    }

    // ─── Filtros de Escopo ────────────────────────────────────────────────────

    private static function applyScopeFilters(array $rows, array $categoria, array $config)
    {
        $region = self::resolveRegionName($categoria);
        if ($region !== null) {
            $states = self::resolveRegionStates($region, $config);
            if (!count($states)) {
                return array();
            }
            $rows = array_values(array_filter($rows, function (ResultRow $row) use ($states) {
                return in_array($row->team->state, $states, true);
            }));
        }

        if (self::isNoviceOnly($categoria)) {
            $noviceTeamIds = self::resolveNoviceTeamIds($categoria, $config);
            if (!count($noviceTeamIds)) {
                return array();
            }
            $rows = array_values(array_filter($rows, function (ResultRow $row) use ($noviceTeamIds) {
                $teamNumber = isset($row->team->number) ? (int)$row->team->number : null;
                return $teamNumber !== null && in_array($teamNumber, $noviceTeamIds, true);
            }));
        }

        return $rows;
    }

    private static function resolveRegionName(array $categoria)
    {
        if (!empty($categoria['region'])) {
            return trim((string)$categoria['region']);
        }
        if (isset($categoria['scope']) && is_array($categoria['scope']) && @$categoria['scope']['type'] === 'region') {
            if (!empty($categoria['scope']['name']))   return trim((string)$categoria['scope']['name']);
            if (!empty($categoria['scope']['region'])) return trim((string)$categoria['scope']['region']);
        }
        return null;
    }

    private static function resolveRegionStates($region, array $config)
    {
        if (!isset($config['regions']) || !is_array($config['regions'])) {
            return array();
        }
        if (!isset($config['regions'][$region]) || !is_array($config['regions'][$region])) {
            return array();
        }
        return array_values(array_unique(array_map('strtoupper', $config['regions'][$region])));
    }

    private static function isNoviceOnly(array $categoria)
    {
        if (!empty($categoria['novice_only'])) {
            return true;
        }
        return isset($categoria['scope']) && is_array($categoria['scope']) && @$categoria['scope']['type'] === 'novice';
    }

    private static function resolveNoviceTeamIds(array $categoria, array $config)
    {
        if (isset($categoria['novice_team_ids']) && is_array($categoria['novice_team_ids'])) {
            return Selection::sanitizeIntArray($categoria['novice_team_ids']);
        }
        if (isset($config['novice_team_ids']) && is_array($config['novice_team_ids'])) {
            return Selection::sanitizeIntArray($config['novice_team_ids']);
        }
        return array();
    }

    // ─── Modo Manual ─────────────────────────────────────────────────────────

    private static function resolveManualCategory($eventoId, array $categoria)
    {
        $headers = self::resolveHeaders(array('#', 'Equipe', 'UF'), $categoria);
        $teamIds = array();
        if (isset($categoria['manual_team_ids']) && is_array($categoria['manual_team_ids'])) {
            $teamIds = Selection::sanitizeIntArray($categoria['manual_team_ids']);
        }

        $rows = count($teamIds) ? self::buildManualRows($eventoId, $teamIds) : array();

        $displayRows = array();
        foreach ($rows as $row) {
            $displayRow = array();
            foreach ($headers as $header) {
                $displayRow[$header] = self::resolveDisplayValue($row, $header);
            }
            $displayRows[] = $displayRow;
        }

        return array(
            'config'  => $categoria,
            'headers' => $headers,
            'rows'    => $displayRows,
            'error'   => null,
        );
    }

    private static function buildManualRows($eventoId, array $teamIds)
    {
        $teams = EquipeQuery::create()
            ->filterByEventoId($eventoId)
            ->filterByEquipeId($teamIds)
            ->find();

        $teamMap = array();
        foreach ($teams as $team) {
            $teamMap[(int)$team->getEquipeId()] = $team;
        }

        $rows     = array();
        $position = 0;
        foreach ($teamIds as $teamId) {
            if (!isset($teamMap[$teamId])) {
                continue;
            }
            $position++;
            $team   = $teamMap[$teamId];
            $rows[] = array(
                '__POS'         => $position,
                '__TEAM_ID'     => (int)$team->getEquipeId(),
                '__TEAM_NUM'    => (int)$team->getEquipeId(),
                '__TEAM_NAME'   => $team->getEquipe(),
                '__TEAM_SCHOOL' => $team->getEscola(),
                '__TEAM_STATE'  => $team->getEstado(),
                '#'             => (int)$team->getEquipeId(),
                'Equipe'        => $team->getEquipe(),
                'UF'            => $team->getEstado(),
            );
        }

        return $rows;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function resolveHeaders(array $availableHeaders, array $categoria)
    {
        if (!empty($categoria['mostrar']) && is_array($categoria['mostrar'])) {
            return $categoria['mostrar'];
        }
        return $availableHeaders;
    }

    private static function resolveDisplayValue(array $row, $header)
    {
        if (array_key_exists($header, $row)) {
            return $row[$header];
        }
        if ($header === '#')      return isset($row['__TEAM_NUM'])    ? $row['__TEAM_NUM']    : '';
        if ($header === 'Equipe') return isset($row['__TEAM_NAME'])   ? $row['__TEAM_NAME']   : '';
        if ($header === 'Escola') return isset($row['__TEAM_SCHOOL']) ? $row['__TEAM_SCHOOL'] : '';
        if ($header === 'UF')     return isset($row['__TEAM_STATE'])  ? $row['__TEAM_STATE']  : '';
        if ($header === 'Pos')    return isset($row['__POS'])         ? $row['__POS']         : '';
        return '';
    }

    private static function buildError(array $categoria, $message)
    {
        return array(
            'config'  => $categoria,
            'headers' => array(),
            'rows'    => array(),
            'error'   => $message,
        );
    }
}
