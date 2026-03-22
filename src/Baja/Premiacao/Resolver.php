<?php

namespace Baja\Premiacao;

use Baja\Model\EquipeQuery;
use Baja\Model\Input;
use Baja\Model\InputQuery;
use Baja\Model\Premiacao;
use Baja\Model\ResultadoQuery;

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
        $rankingHeader   = isset($dataset['position_metric']) ? $dataset['position_metric'] : null;
        $rankingDirection = isset($dataset['position_order'])  ? $dataset['position_order']  : 'desc';
        $positionHeader  = isset($dataset['position_header']) ? $dataset['position_header'] : null;

        if ($modo === 'column_top') {
            $sortHeader = isset($categoria['sort_header']) ? $categoria['sort_header'] : null;
            if (!$sortHeader) {
                return self::buildError($categoria, 'sort_header não informado.');
            }
            if (!in_array($sortHeader, $dataset['headers'], true)) {
                return self::buildError($categoria, 'Cabeçalho não encontrado no resultado: ' . $sortHeader);
            }
            $rankingHeader    = $sortHeader;
            $rankingDirection = isset($categoria['direction']) ? strtolower($categoria['direction']) : 'desc';
        }

        if ($rankingHeader !== null) {
            $rows = self::sortRowsByHeader($rows, $rankingHeader, $rankingDirection);
            $rows = self::applyCompetitionPositions($rows, $rankingHeader, $positionHeader);
        }

        $rows = self::applySelection($rows, $categoria);

        $headers = self::resolveHeaders($dataset['headers'], $categoria);
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

    // ─── Dataset ──────────────────────────────────────────────────────────────

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

        $columns        = (array)$config->colunas;
        $headers        = self::extractHeaders($columns);
        $positionHeader = self::findPositionHeader($columns);
        $positionMetric = isset($config->pos)       ? $config->pos                    : null;
        $positionOrder  = isset($config->pos_order) ? strtolower($config->pos_order)  : 'desc';
        $filter         = isset($config->filter)    ? $config->filter                  : null;

        $items = InputQuery::create()
            ->filterByEventoId($eventoId)
            ->filterByProvaId($resultado->getInputs())
            ->leftJoinEquipe()
            ->withColumn('Equipe.Escola')
            ->withColumn('Equipe.EquipeId')
            ->withColumn('Equipe.Equipe')
            ->withColumn('Equipe.Estado')
            ->find();

        $vars = array();
        foreach ($items as $input) {
            $teamId = $input->getEquipeId();
            if (!array_key_exists($teamId, $vars)) {
                $vars[$teamId] = array(
                    'TEAM_ID' => $teamId,
                    'NUM'     => $input->getEquipeEquipeId(),
                    'EQUIPE'  => $input->getEquipeEquipe(),
                    'ESCOLA'  => $input->getEquipeEscola(),
                    'ESTADO'  => $input->getEquipeEstado(),
                );
            }

            $dados = (array)$input->getDados();
            if (!@$input->getDados()->entries) {
                if (!empty($vars[$teamId]['entries'])) {
                    $current = $vars[$teamId]['entries'][0];
                    $dados = array('entries' => array(array_merge((array)$current, (array)$input->getDados())));
                } else {
                    $dados = array('entries' => array($input->getDados()));
                }
            }

            $vars[$teamId] = array_merge(
                $vars[$teamId],
                $dados,
                (array)$input->getVars(),
                (array)$input->getPontos()
            );

            if ($filter && (!isset($vars[$teamId][$filter]) || !$vars[$teamId][$filter])) {
                unset($vars[$teamId]);
            }
        }

        $rows = array();
        foreach ($vars as $varSet) {
            if (!isset($varSet['entries']) || !is_array($varSet['entries'])) {
                continue;
            }
            foreach ($varSet['entries'] as $entry) {
                $rowVars = array_merge($varSet, (array)$entry);
                unset($rowVars['entries']);
                $rows[] = self::buildDisplayRow($rowVars, $columns);
            }
        }

        if ($positionMetric) {
            $rows = self::sortRowsByHeader($rows, $positionMetric, $positionOrder);
            $rows = self::applyCompetitionPositions($rows, $positionMetric, $positionHeader);
        }

        self::$datasetCache[$cacheKey] = array(
            'headers'          => $headers,
            'rows'             => $rows,
            'position_header'  => $positionHeader,
            'position_metric'  => $positionMetric,
            'position_order'   => $positionOrder,
            'error'            => null,
        );

        return self::$datasetCache[$cacheKey];
    }

    private static function buildDisplayRow(array $vars, array $columns)
    {
        $row = array(
            '__TEAM_ID'     => isset($vars['TEAM_ID']) ? $vars['TEAM_ID'] : null,
            '__TEAM_NUM'    => isset($vars['NUM'])     ? $vars['NUM']     : null,
            '__TEAM_NAME'   => isset($vars['EQUIPE'])  ? $vars['EQUIPE']  : null,
            '__TEAM_SCHOOL' => isset($vars['ESCOLA'])  ? $vars['ESCOLA']  : null,
            '__TEAM_STATE'  => isset($vars['ESTADO'])  ? $vars['ESTADO']  : null,
        );

        foreach ($columns as $column) {
            $header = isset($column->header) ? $column->header : null;
            if ($header === null) {
                continue;
            }

            if ($column->val === 'EquipeNum') {
                $row[$header] = isset($vars['NUM']) ? $vars['NUM'] : null;
                continue;
            }
            if ($column->val === 'Equipe') {
                $row[$header] = isset($vars['EQUIPE']) ? $vars['EQUIPE'] : null;
                continue;
            }
            if ($column->val === 'Pos') {
                $row[$header] = null;
                continue;
            }

            if (is_array($column->val)) {
                $highlight = isset($column->highlight) ? Input::solveFormula($vars, $column->highlight) : null;
                $values = array();
                foreach ($column->val as $formula) {
                    $value = Input::solveFormula($vars, $formula);
                    if ($highlight !== null && $value == $highlight) {
                        $value = '<b>' . $value . '</b>';
                    }
                    $values[] = $value;
                }
                $row[$header] = implode('<br /> ', $values);
                continue;
            }

            $row[$header] = Input::solveFormula($vars, $column->val);
        }

        return $row;
    }

    // ─── Ordenação e Posições ─────────────────────────────────────────────────

    private static function sortRowsByHeader(array $rows, $header, $direction)
    {
        usort($rows, function ($left, $right) use ($header, $direction) {
            $leftValue  = self::extractNumericValue(isset($left[$header])  ? $left[$header]  : null);
            $rightValue = self::extractNumericValue(isset($right[$header]) ? $right[$header] : null);

            if ($leftValue === $rightValue) {
                $leftNum  = isset($left['__TEAM_NUM'])  ? (int)$left['__TEAM_NUM']  : 999999;
                $rightNum = isset($right['__TEAM_NUM']) ? (int)$right['__TEAM_NUM'] : 999999;
                return ($leftNum === $rightNum) ? 0 : (($leftNum < $rightNum) ? -1 : 1);
            }

            if ($direction === 'asc') {
                return ($leftValue < $rightValue) ? -1 : 1;
            }

            return ($leftValue > $rightValue) ? -1 : 1;
        });

        return $rows;
    }

    private static function applyCompetitionPositions(array $rows, $header, $positionHeader = null)
    {
        $position  = 0;
        $ordinal   = 0;
        $lastValue = null;

        foreach ($rows as &$row) {
            $ordinal++;
            $currentValue = self::extractNumericValue(isset($row[$header]) ? $row[$header] : null);
            if ($lastValue === null || $currentValue !== $lastValue) {
                $position = $ordinal;
            }
            $row['__POS'] = $position;
            if ($positionHeader !== null) {
                $row[$positionHeader] = $position;
            }
            $lastValue = $currentValue;
        }
        unset($row);

        return $rows;
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
        $cutoffPosition = isset($lastSelected['__POS']) ? (int)$lastSelected['__POS'] : null;
        if ($cutoffPosition === null) {
            return $selected;
        }

        for ($i = $topN; $i < count($rows); $i++) {
            $position = isset($rows[$i]['__POS']) ? (int)$rows[$i]['__POS'] : null;
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
            $position = isset($row['__POS']) ? (int)$row['__POS'] : null;
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
            $rows = array_values(array_filter($rows, function ($row) use ($states) {
                return in_array(isset($row['__TEAM_STATE']) ? $row['__TEAM_STATE'] : null, $states, true);
            }));
        }

        if (self::isNoviceOnly($categoria)) {
            $noviceTeamIds = self::resolveNoviceTeamIds($categoria, $config);
            if (!count($noviceTeamIds)) {
                return array();
            }
            $rows = array_values(array_filter($rows, function ($row) use ($noviceTeamIds) {
                $teamNumber = isset($row['__TEAM_NUM']) ? (int)$row['__TEAM_NUM'] : null;
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

    private static function extractHeaders(array $columns)
    {
        $headers = array();
        foreach ($columns as $column) {
            if (isset($column->header)) {
                $headers[] = $column->header;
            }
        }
        return $headers;
    }

    private static function findPositionHeader(array $columns)
    {
        foreach ($columns as $column) {
            if (isset($column->val) && $column->val === 'Pos' && isset($column->header)) {
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

    private static function extractNumericValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float)$value;
        }
        $text = strip_tags((string)$value);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace(',', '.', $text);
        if (!preg_match('/-?\d+(?:\.\d+)?/', $text, $matches)) {
            return null;
        }
        return (float)$matches[0];
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
