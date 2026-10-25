<?php
declare(strict_types=1);

namespace Baja\Api\Resultados;

use Baja\Model\Evento;
use Baja\Model\EventoQuery;
use Baja\Model\Prova;
use Baja\Model\ProvaQuery;
use Baja\Model\Resultado;
use Baja\Model\ResultadoQuery;

/**
 * Finds what an id in the query string names, or answers 404.
 *
 * The id must be written exactly as stored: MySQL compares these columns
 * without regard to case, so "26br" would find 26BR, and Cloudflare would
 * then keep a second copy of the same answer under another URL. Refusing it
 * keeps one URL per answer.
 */
final class Lookup
{
    public static function evento(string $eventoId): Evento
    {
        $evento = EventoQuery::create()->findPk($eventoId);
        if ($evento === null || $evento->getEventoId() !== $eventoId) {
            throw ApiError::notFound('Evento não encontrado.');
        }
        return $evento;
    }

    /** A resultado of this event, as resultados/prova.php finds it. */
    public static function resultado(Evento $evento, string $resultadoId): Resultado
    {
        $resultado = ResultadoQuery::create()->filterByEventoId($evento->getEventoId())->findPk($resultadoId);
        if ($resultado === null || $resultado->getResultadoId() !== $resultadoId) {
            throw ApiError::notFound('Resultado não encontrado neste evento.');
        }
        return $resultado;
    }

    public static function prova(Evento $evento, string $provaId): Prova
    {
        $prova = ProvaQuery::create()->filterByEventoId($evento->getEventoId())->findOneByProvaId($provaId);
        if ($prova === null || $prova->getProvaId() !== $provaId) {
            throw ApiError::notFound('Prova não encontrada neste evento.');
        }
        return $prova;
    }
}
