<?php

namespace Baja\Premiacao;

use Baja\Model\EquipeQuery;
use Baja\Model\Premiacao;
use Baja\Model\ResultadoQuery;
use Baja\Results\Behaviour\PremiacaoBehaviour;
use Baja\Results\Column;
use Baja\Results\Ranking\ScoreRanking;
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
        $rankingHeader      = isset($dataset['position_metric']) ? $dataset['position_metric'] : null;
        $rankingDirection   = isset($dataset['position_order'])  ? $dataset['position_order']  : 'desc';
        $positionHeader     = isset($dataset['position_header']) ? $dataset['position_header'] : null;
        $rankingUsesTiebreak = !empty($dataset['uses_tiebreak']);

        if ($modo === 'column_top') {
            $sortHeader = isset($categoria['sort_header']) ? $categoria['sort_header'] : null;
            if (!$sortHeader) {
                return self::buildError($categoria, 'sort_header não informado.');
            }
            if (!in_array($sortHeader, $dataset['headers'], true)) {
                return self::buildError($categoria, 'Cabeçalho não encontrado no resultado: ' . $sortHeader);
            }
            $rankingHeader       = $sortHeader;
            $rankingDirection    = isset($categoria['direction']) ? strtolower($categoria['direction']) : 'desc';
            $rankingUsesTiebreak = false;
        }

        if ($rankingHeader !== null) {
            $rows = ScoreRanking::rank($rows, is_int($rankingHeader) ? $rankingHeader : (string)$rankingHeader, $rankingDirection, $rankingUsesTiebreak);
        }

        $rows = self::applySelection($rows, $categoria);

        $headers = self::resolveHeaders($dataset['headers'], $categoria);
        $displayRows = array();
        foreach ($rows as $row) {
            $row = self::legacyRow($row, $positionHeader);
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
     * The resultado's rows, as the premiação has always computed them
     * (Baja\Results\Behaviour\PremiacaoBehaviour), ranked by its "pos".
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
        $table = ResultsBuilder::forPremiacao()->buildFor($resultado, $resultsConfig, ViewerContext::premiacao());
        $columns = PremiacaoBehaviour::columns($resultsConfig);

        self::$datasetCache[$cacheKey] = array(
            'headers'          => array_map(function (Column $column) { return $column->header; }, $columns),
            'rows'             => $table->rows,
            'position_header'  => self::findPositionHeader($columns),
            'position_metric'  => $resultsConfig->pos,
            'position_order'   => PremiacaoBehaviour::direction($resultsConfig),
            'uses_tiebreak'    => PremiacaoBehaviour::usesTiebreak($resultsConfig),
            'error'            => null,
        );

        return self::$datasetCache[$cacheKey];
    }

    /**
     * A row as the display code below reads it: the team under "__TEAM_*"
     * keys, then each column by header. Only the first "Pos" column shows
     * the position; any other is empty, as it always was here.
     *
     * @return array<string|int, mixed>
     */
    private static function legacyRow(ResultRow $row, $positionHeader)
    {
        $legacy = array(
            '__TEAM_ID'     => $row->team->teamId,
            '__TEAM_NUM'    => $row->team->number,
            '__TEAM_NAME'   => $row->team->name,
            '__TEAM_SCHOOL' => $row->team->school,
            '__TEAM_STATE'  => $row->team->state,
            '__TIEBREAK'    => $row->tiebreak,
        );
        foreach ($row->cells() as $header => $cell) {
            $legacy[$header] = $cell->kind === Column::KIND_POSITION ? null : $cell->display;
        }
        if ($row->position !== null) {
            $legacy['__POS'] = $row->position;
            if ($positionHeader !== null) {
                $legacy[$positionHeader] = $row->position;
            }
        }
        return $legacy;
    }

    // ─── Seleção ──────────────────────────────────────────────────────────────

    private static function applySelection(array $rows, array $categoria)
    {
        $positions    = self::resolvePositions($categoria);
        $includeTies  = self::resolveIncludeTies($categoria, !empty($positions));

        if (!empty($positions)) {
            return self::selectByPositions($rows, $positions, $includeTies);
        }

        $topN = isset($categoria['top_n']) ? (int)$categoria['top_n'] : count($rows);
        if ($topN <= 0 || $topN >= count($rows)) {
            return $rows;
        }

        $selected = array_slice($rows, 0, $topN);
        if (!$includeTies) {
            return $selected;
        }

        $lastSelected   = end($selected);
        $cutoffPosition = $lastSelected->position;
        if ($cutoffPosition === null) {
            return $selected;
        }

        for ($i = $topN; $i < count($rows); $i++) {
            $position = $rows[$i]->position;
            if ($position !== $cutoffPosition) {
                break;
            }
            $selected[] = $rows[$i];
        }

        return $selected;
    }

    private static function selectByPositions(array $rows, array $positions, $includeTies)
    {
        $positionMap = array();
        foreach ($positions as $position) {
            $positionMap[(int)$position] = true;
        }

        $selected = array();
        $taken    = array();
        foreach ($rows as $row) {
            $position = $row->position;
            if ($position === null || !isset($positionMap[$position])) {
                continue;
            }
            if (!$includeTies && isset($taken[$position])) {
                continue;
            }
            $selected[]       = $row;
            $taken[$position] = true;
        }

        return $selected;
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
            return self::sanitizeIntArray($categoria['novice_team_ids']);
        }
        if (isset($config['novice_team_ids']) && is_array($config['novice_team_ids'])) {
            return self::sanitizeIntArray($config['novice_team_ids']);
        }
        return array();
    }

    // ─── Modo Manual ─────────────────────────────────────────────────────────

    private static function resolveManualCategory($eventoId, array $categoria)
    {
        $headers = self::resolveHeaders(array('#', 'Equipe', 'UF'), $categoria);
        $teamIds = array();
        if (isset($categoria['manual_team_ids']) && is_array($categoria['manual_team_ids'])) {
            $teamIds = self::sanitizeIntArray($categoria['manual_team_ids']);
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

    /** @param list<Column> $columns */
    private static function findPositionHeader(array $columns)
    {
        foreach ($columns as $column) {
            if ($column->kind() === Column::KIND_POSITION && $column->header !== null) {
                return $column->header;
            }
        }
        return null;
    }

    private static function resolvePositions(array $categoria)
    {
        if (isset($categoria['positions']) && is_array($categoria['positions'])) {
            return self::sanitizeIntArray($categoria['positions']);
        }
        if (isset($categoria['selection']) && is_array($categoria['selection'])) {
            if (isset($categoria['selection']['positions']) && is_array($categoria['selection']['positions'])) {
                return self::sanitizeIntArray($categoria['selection']['positions']);
            }
            if (isset($categoria['selection']['values']) && is_array($categoria['selection']['values'])) {
                return self::sanitizeIntArray($categoria['selection']['values']);
            }
        }
        return array();
    }

    private static function resolveIncludeTies(array $categoria, $default)
    {
        if (array_key_exists('include_ties', $categoria)) {
            return (bool)$categoria['include_ties'];
        }
        if (isset($categoria['selection']) && is_array($categoria['selection']) && array_key_exists('include_ties', $categoria['selection'])) {
            return (bool)$categoria['selection']['include_ties'];
        }
        return $default;
    }

    private static function sanitizeIntArray(array $values)
    {
        $result = array();
        foreach ($values as $value) {
            $value = (int)$value;
            if ($value <= 0 || in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }
        return $result;
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
