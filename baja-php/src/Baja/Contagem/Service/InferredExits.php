<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Model\ContagemDecisaoQuery;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\ContagemOcorrenciaQuery;
use Baja\Model\Prova;
use Propel\Runtime\ActiveQuery\Criteria;

/**
 * "Marcar saída do box" / "do abastecimento": exits recorded by a reviewer.
 *
 * A car passes the counting line while the pit log still has it inside an
 * area. Almost always the person at the exit missed it. The reviewer then
 * records the exit nobody logged: one second before the pass, so the stay
 * is closed and the car's later passes stop being flagged.
 *
 * Such an exit is an ordinary stop event in `contagem_ocorrencia` (race
 * control lists it and can correct or cancel it), marked with
 * `origem = 'revisor'` so every screen can show it as inferred. The review
 * side also stores a decision of type `marcar_saida` beside it, which is
 * what makes it appear in the decision history and be undone from there.
 *
 * Nothing here reruns the engine: the caller holds the count lock and does
 * that afterwards, like after any decision.
 */
final class InferredExits
{
    /**
     * @param string $area 'box' or 'abastecimento'
     * @return int the id of the new event
     */
    public static function record(Prova $prova, string $area, int $carro, int $tsMs, string $usuario): int
    {
        $row = new ContagemOcorrencia();
        $row->setEventoId($prova->getEventoId());
        $row->setProvaId($prova->getProvaId());
        $row->setTipo(self::exitTypeOf($area));
        $row->setEquipeNumero($carro);
        $row->setTsMs($tsMs);
        $row->setUsuario($usuario);
        $row->setCriadoEmMs(Clock::nowMs());
        $row->setOrigem(EngineInputLoader::ORIGEM_REVISOR);
        $row->save();
        return (int) $row->getOcorrenciaId();
    }

    /**
     * Cancels the exit a `marcar_saida` decision recorded, the way race
     * control cancels any event: with a new row that points at it.
     *
     * The exit is found by what the decision holds: the area (in `motivo`),
     * the car and the time.
     *
     * @throws \InvalidArgumentException with a message in Portuguese when the decision is not of this prova
     */
    public static function cancel(Prova $prova, int $decisaoId, string $usuario): void
    {
        $decision = ContagemDecisaoQuery::create()->findPk($decisaoId);
        $belongsHere = $decision !== null
            && $decision->getEventoId() === $prova->getEventoId()
            && $decision->getProvaId() === $prova->getProvaId();
        if (!$belongsHere) {
            throw new \InvalidArgumentException('Decisão não encontrada.');
        }
        $exit = ContagemOcorrenciaQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(self::exitTypeOf((string) $decision->getMotivo()))
            ->filterByEquipeNumero($decision->getEquipeNumero())
            ->filterByTsMs($decision->getTsMs())
            ->filterByOrigem(EngineInputLoader::ORIGEM_REVISOR)
            ->orderByOcorrenciaId(Criteria::DESC)
            ->findOne();
        if ($exit === null) {
            // Already corrected or removed in race control: nothing left to cancel.
            return;
        }
        $cancellation = new ContagemOcorrencia();
        $cancellation->setEventoId($prova->getEventoId());
        $cancellation->setProvaId($prova->getProvaId());
        $cancellation->setTipo(Ocorrencia::CANCELAMENTO);
        $cancellation->setTsMs($exit->getTsMs());
        $cancellation->setUsuario($usuario);
        $cancellation->setCriadoEmMs(Clock::nowMs());
        $cancellation->setSubstituiId($exit->getOcorrenciaId());
        $cancellation->save();
    }

    private static function exitTypeOf(string $area): string
    {
        if ($area === 'box') {
            return Ocorrencia::BOX_SAIDA;
        }
        if ($area === 'abastecimento') {
            return Ocorrencia::ABASTECIMENTO_SAIDA;
        }
        throw new \InvalidArgumentException('Área desconhecida: ' . $area);
    }
}
