<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Model\ContagemDecisao;
use Baja\Model\ContagemDecisaoQuery;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery;
use Baja\Model\Prova;

/**
 * Writes the records people make about a race: race-control events and
 * reviewers' decisions.
 *
 * Both tables are append-only. Nothing is ever edited or deleted: a
 * correction, a cancellation or an undo is a NEW row that points at the
 * one it changes. That is what lets every protest be answered with "who
 * did what, when".
 *
 * Nothing here reruns the engine. The caller holds the count lock and
 * recounts afterwards, so that it can answer with the state the record
 * produced.
 */
final class RaceRecords
{
    private Prova $prova;

    public function __construct(Prova $prova)
    {
        $this->prova = $prova;
    }

    /**
     * @param string $tipo one of Ocorrencia::TIPOS
     * @param int|null $substituiId the earlier event this one corrects or cancels, if any
     * @throws \InvalidArgumentException for an unknown type
     */
    public function storeOcorrencia(string $tipo, int $tsMs, ?int $carro, string $usuario, ?int $substituiId = null): void
    {
        if (!in_array($tipo, Ocorrencia::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo de ocorrência desconhecido: ' . $tipo);
        }
        $row = new ContagemOcorrencia();
        $row->setEventoId($this->prova->getEventoId());
        $row->setProvaId($this->prova->getProvaId());
        $row->setTipo($tipo);
        $row->setEquipeNumero($carro);
        $row->setTsMs($tsMs);
        $row->setUsuario($usuario);
        $row->setCriadoEmMs(Clock::nowMs());
        $row->setSubstituiId($substituiId);
        $row->save();
    }

    /** True when race control recorded a start for this race at any time, even one cancelled since. */
    public function aStartWasEverRecorded(): bool
    {
        return ContagemOcorrenciaQuery::create()
            ->filterByEventoId($this->prova->getEventoId())
            ->filterByProvaId($this->prova->getProvaId())
            ->filterByTipo(Ocorrencia::LARGADA)
            ->count() > 0;
    }

    /** Stores a correction and returns its id. */
    public function storeDecision(Decision $decision, string $usuario, ?string $motivo): int
    {
        $row = new ContagemDecisao();
        $row->setEventoId($this->prova->getEventoId());
        $row->setProvaId($this->prova->getProvaId());
        $row->setTipo($decision->tipo);
        $row->setPassagemId($decision->passagemId);
        $row->setFonteId($decision->fonteId);
        $row->setEquipeNumero($decision->carro);
        $row->setTsMs($decision->tsMs);
        $row->setTsFimMs($decision->tsFimMs);
        $row->setDesfazDecisaoId($decision->desfazDecisaoId);
        $row->setOrigem($decision->origem);
        $row->setUsuario($usuario);
        $row->setMotivo($motivo);
        $row->setCriadoEmMs(Clock::nowMs());
        $row->save();

        return (int) $row->getDecisaoId();
    }

    /**
     * Gives an open-ended exclusion of a source an end ("the phone was
     * fixed at 14:32").
     *
     * Decisions are never edited, so this is stored as two new ones: the
     * open exclusion is undone, and a bounded one with the same source and
     * the same start takes its place.
     *
     * @return int the id of the new, bounded exclusion
     * @throws \InvalidArgumentException with a message in Portuguese, fit to show to the reviewer
     */
    public function closeOpenExclusion(int $decisaoId, int $tsFimMs, string $usuario, ?string $motivo): int
    {
        $open = ContagemDecisaoQuery::create()->findPk($decisaoId);
        $belongsHere = $open !== null
            && $open->getEventoId() === $this->prova->getEventoId()
            && $open->getProvaId() === $this->prova->getProvaId();
        if (!$belongsHere || $open->getTipo() !== Decision::EXCLUIR_FONTE) {
            throw new \InvalidArgumentException('Exclusão de fonte não encontrada.');
        }
        if ($open->getTsFimMs() !== null) {
            throw new \InvalidArgumentException('Esta exclusão já tem um fim.');
        }
        $alreadyUndone = ContagemDecisaoQuery::create()
            ->filterByTipo(Decision::DESFAZER)
            ->filterByDesfazDecisaoId($decisaoId)
            ->count() > 0;
        if ($alreadyUndone) {
            throw new \InvalidArgumentException('Esta exclusão já foi desfeita.');
        }
        if ($open->getTsMs() !== null && $tsFimMs <= (int) $open->getTsMs()) {
            throw new \InvalidArgumentException('O fim da exclusão precisa ser depois do início.');
        }

        $this->storeDecision(Decision::desfazer(0, $decisaoId), $usuario, 'Substituída por uma exclusão com fim');
        $startMs = $open->getTsMs() === null ? null : (int) $open->getTsMs();
        $bounded = Decision::excluirFonte(0, (int) $open->getFonteId(), $startMs, $tsFimMs);
        return $this->storeDecision($bounded, $usuario, $motivo ?? $open->getMotivo());
    }
}
