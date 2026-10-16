<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Sync\EntryWriter;
use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * Changing the lap counting settings of a prova, and the rules that make
 * a rehearsal safe to leave.
 *
 * Settings live in `prova.params.contagem`. A setting can change which
 * passes count, so every change recounts and republishes the whole race,
 * under the count lock.
 *
 * Two settings exist for rehearsals: "Permitir simulação", and the test
 * preset, which scales the time settings down to a race with laps of a few
 * seconds and keeps the real ones aside. Both are dangerous to forget, so:
 *
 *   - leaving the preset puts back ONLY the time settings and switches
 *     simulation off; whatever else was changed meanwhile stays;
 *   - simulation cannot be switched off while made-up records exist (they
 *     would be counted as a real race), nor while the preset's timings are
 *     still in place (a real race with a one-second matching window).
 */
final class SettingsChanges
{
    private Prova $prova;
    private ContagemService $service;
    private PublishLog $publishLog;

    public function __construct(Prova $prova, ContagemService $service, PublishLog $publishLog)
    {
        $this->prova = $prova;
        $this->service = $service;
        $this->publishLog = $publishLog;
    }

    /**
     * @throws \InvalidArgumentException with a message in Portuguese when the change is refused
     */
    public function save(Settings $settings): void
    {
        $this->service->withLock(function () use ($settings): void {
            $this->prova->reload();
            if ($this->service->getSettings()->permitirSimulacao && !$settings->permitirSimulacao) {
                $this->refuseToLeaveSimulationBehind();
                if ($this->presetIsApplied()) {
                    throw new \InvalidArgumentException(
                        'A predefinição de teste está aplicada. Use "Restaurar configurações salvas": ela devolve os tempos reais e desliga a simulação.'
                    );
                }
            }
            $this->store($settings);
        });
    }

    /**
     * "Predefinição de teste": replaces the time settings by ones scaled to
     * a race with this average lap, and allows simulation. The settings in
     * force before are kept, see restore().
     */
    public function applyTestPreset(int $voltaMediaS): void
    {
        $this->service->withLock(function () use ($voltaMediaS): void {
            $this->prova->reload();
            TestPreset::remember($this->prova);
            $this->store(TestPreset::applyTo($this->service->getSettings(), $voltaMediaS));
        });
    }

    /**
     * Leaves the test preset: puts back the TIME settings that were in
     * force before it and switches simulation off. Every other setting is
     * left as it is now.
     *
     * @param bool $confirmedPublishing the person has agreed that the counts start going to the official results
     * @return bool false when there was nothing to put back
     * @throws \InvalidArgumentException while simulated records exist (message for the person)
     * @throws PublishingWouldStart when this would start publishing and that was not confirmed
     */
    public function restore(bool $confirmedPublishing = false): bool
    {
        return $this->service->withLock(function () use ($confirmedPublishing): bool {
            $this->prova->reload();
            $restored = TestPreset::restored($this->prova);
            if ($restored === null) {
                return false;
            }
            $this->refuseToLeaveSimulationBehind();
            if (!$this->service->getSettings()->publishesToScoring() && $restored->publishesToScoring() && !$confirmedPublishing) {
                throw new PublishingWouldStart('Restaurar as configurações voltaria a publicar a contagem nos resultados oficiais.');
            }
            TestPreset::forget($this->prova);
            $this->store($restored);
            return true;
        });
    }

    /** True while settings from before a test preset are kept, waiting to be restored. */
    public function presetIsApplied(): bool
    {
        return TestPreset::hasRemembered($this->prova);
    }

    /** True when the prova holds entries or stop events made by the simulation. */
    public function hasSimulatedRows(): bool
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT EXISTS (SELECT 1 FROM contagem_passagem p JOIN contagem_fonte f ON f.fonte_id = p.fonte_id
                             WHERE f.evento_id = :evento AND f.prova_id = :prova AND p.modo_entrada = :modo)
                 OR EXISTS (SELECT 1 FROM contagem_ocorrencia o
                             WHERE o.evento_id = :evento2 AND o.prova_id = :prova2 AND o.modo_entrada = :modo2)'
        );
        $statement->execute([
            ':evento' => $this->prova->getEventoId(), ':prova' => $this->prova->getProvaId(), ':modo' => EntryWriter::MODE_SIMULATION,
            ':evento2' => $this->prova->getEventoId(), ':prova2' => $this->prova->getProvaId(), ':modo2' => EntryWriter::MODE_SIMULATION,
        ]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        return is_array($row) && (int) $row[0] === 1;
    }

    private function refuseToLeaveSimulationBehind(): void
    {
        if ($this->hasSimulatedRows()) {
            throw new \InvalidArgumentException(
                'Esta prova tem registros de simulação. Use "Zerar corrida" (Controle de corrida) antes de desligar a simulação.'
            );
        }
    }

    /** Writes the settings and recounts. The caller holds the lock. */
    private function store(Settings $settings): void
    {
        $publishedBefore = $this->service->getSettings()->publishesToScoring();

        $params = $this->prova->getParams() ?? new \stdClass();
        $params->contagem = (object) $settings->toArray();
        $this->prova->setParams($params);
        $this->prova->save();
        $result = $this->service->recomputeAndPublish();

        if (!$publishedBefore && $settings->publishesToScoring()) {
            // While publishing was off, starting the race here did not set
            // the race clock of the results pages (the spreadsheet owned
            // it). Now that the counts are ours, the clock must be too, or
            // LAST would be measured from one start and shown against
            // another.
            $this->publishLog->publishStartTime($result);
        }
    }
}
