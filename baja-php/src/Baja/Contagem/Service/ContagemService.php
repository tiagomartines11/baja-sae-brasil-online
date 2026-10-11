<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Decision;
use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Engine\Engine;
use Baja\Contagem\Engine\Settings;
use Baja\Model\ContagemDecisao;
use Baja\Model\ContagemOcorrencia;
use Baja\Model\Prova;

/**
 * The lap counting of one prova, as the pages and endpoints see it.
 *
 * This class decides nothing itself. It strings the pieces together in the
 * right order: load the raw data (EngineInputLoader), run the pure Engine,
 * save the laps (LapStore), publish the scores (ScorePublisher) and tell the
 * review screens that something changed (VersionStore).
 */
final class ContagemService
{
    private Prova $prova;
    private EngineInputLoader $loader;
    private LapStore $lapStore;
    private ScorePublisher $publisher;
    private VersionStore $versions;
    private ProvaLock $lock;
    private RaceReset $raceReset;
    private ?PublishOutcome $lastPublishOutcome = null;

    private function __construct(Prova $prova)
    {
        $this->prova = $prova;
        $this->loader = new EngineInputLoader($prova);
        $this->lapStore = new LapStore($prova);
        $this->publisher = new ScorePublisher($prova);
        $this->versions = new VersionStore($prova);
        $this->lock = new ProvaLock($prova);
        $this->raceReset = new RaceReset($prova);
    }

    public static function forProva(Prova $prova): self
    {
        return new self($prova);
    }

    public function getProva(): Prova
    {
        return $this->prova;
    }

    public function getSettings(): Settings
    {
        return Settings::fromArray($this->prova->getParams()->contagem ?? null);
    }

    /** Stores the settings in prova.params.contagem, leaving the rest of params alone. */
    public function saveSettings(Settings $settings): void
    {
        $params = $this->prova->getParams() ?? new \stdClass();
        $params->contagem = (object) $settings->toArray();
        $this->prova->setParams($params);
        $this->prova->save();
        $this->versions->bump();
    }

    /**
     * Runs $work while holding the per-prova lock and returns its result.
     * Safe to nest: code that already holds the lock may call it again.
     *
     * @return mixed
     */
    public function withLock(callable $work)
    {
        return $this->lock->run($work);
    }

    /** Loads everything and runs the engine. Writes nothing. */
    public function compute(): EngineResult
    {
        return Engine::run($this->loadInput());
    }

    public function loadInput(): EngineInput
    {
        return $this->loader->load($this->getSettings());
    }

    /**
     * Teams of the event keyed by car number, for names on screens.
     *
     * @return array<int, array{numero: int, nome: string, escola: string}>
     */
    public function loadTeams(bool $onlyPresent = true): array
    {
        return $this->loader->loadTeams($onlyPresent);
    }

    /**
     * Runs the engine, saves the laps, publishes the scores and bumps the
     * state version. The caller must hold the lock (see withLock()).
     *
     * Use this after a change made by a person (a decision, a race-control
     * event, new settings): the version is bumped unconditionally so every
     * review screen reloads.
     */
    public function recomputeAndPublish(): EngineResult
    {
        return $this->recompute(true);
    }

    /**
     * The same as recomputeAndPublish(), for the upload endpoint. Devices
     * send a heartbeat every few seconds, and most of them carry nothing
     * new; the version is then bumped only when the engine result actually
     * differs, so idle review screens are not made to reload for nothing.
     *
     * @param bool $storedSomething true when this upload added an entry, a void or a pit event
     */
    public function recomputeAfterSync(bool $storedSomething): EngineResult
    {
        return $this->recompute($storedSomething);
    }

    /** "Republicar tudo": rewrites every car's VOLTAS/LAST/BEST and recalculates points. */
    public function republishAll(): void
    {
        $this->withLock(function (): void {
            $this->lastPublishOutcome = $this->publisher->publish($this->compute(), true);
            $this->versions->markPublished(Clock::nowMs());
            $this->versions->bump();
        });
    }

    /** "Reconstruir": throws contagem_volta away and writes it again from the raw data. */
    public function rebuildLaps(): void
    {
        $this->withLock(function (): void {
            $this->lapStore->replace($this->compute()->laps, true);
            $this->versions->bump();
        });
    }

    /**
     * "Zerar corrida": deletes the raw data of this prova (entries,
     * decisions, race-control and pit events, laps, imported totals), sets
     * the published counts back to zero and clears the start time. Sources,
     * counter links and devices are kept.
     */
    public function resetRace(): void
    {
        $this->withLock(function (): void {
            $this->raceReset->deleteRawData();
            $this->lapStore->deleteAll();
            $this->lastPublishOutcome = $this->publisher->publishZeros();
            $this->prova->setTempo(null);
            $this->prova->save();
            $this->versions->markPublished(Clock::nowMs());
            $this->versions->bump();
        });
    }

    /**
     * Stores a race-control (or pit) event and reruns everything. The
     * caller must hold the lock.
     *
     * A start also sets prova.tempo, in Unix seconds: the public results
     * pages and the overlay take their race clock from that column.
     *
     * @param string $tipo one of Ocorrencia::TIPOS
     * @param int|null $substituiId the earlier event this one corrects, if any
     */
    public function recordOcorrencia(string $tipo, int $tsMs, ?int $carro, string $usuario, ?int $substituiId = null): void
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

        if ($tipo === Ocorrencia::LARGADA) {
            $this->prova->setTempo(intdiv($tsMs, 1000));
            $this->prova->save();
        }

        $this->recomputeAndPublish();
    }

    /**
     * Stores a correction and returns its id. Does not rerun the engine:
     * the caller does that (recomputeAndPublish()) while still holding the
     * lock, so it can answer with the state the decision produced.
     */
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

    public function currentVersion(): int
    {
        return $this->versions->current();
    }

    public function bumpVersion(): int
    {
        return $this->versions->bump();
    }

    public function lastPublishedAtMs(): ?int
    {
        return $this->versions->lastPublishedAtMs();
    }

    /** What the most recent publish through this object did; null if none ran yet. */
    public function lastPublishOutcome(): ?PublishOutcome
    {
        return $this->lastPublishOutcome;
    }

    private function recompute(bool $alwaysBumpVersion): EngineResult
    {
        $result = $this->compute();

        $lapsRewritten = $this->lapStore->replace($result->laps);

        $outcome = $this->publisher->publish($result);
        $this->lastPublishOutcome = $outcome;
        if ($outcome->anythingChanged()) {
            $this->versions->markPublished(Clock::nowMs());
        }

        // Asked unconditionally: it also records the fingerprint for next time.
        $resultChanged = $this->versions->resultChanged(self::fingerprint($result));

        if ($alwaysBumpVersion || $lapsRewritten || $outcome->anythingChanged() || $resultChanged) {
            $this->versions->bump();
        }
        return $result;
    }

    /**
     * A short digest of everything in the result that a review screen
     * shows and that does not change merely because time passed.
     */
    private static function fingerprint(EngineResult $result): string
    {
        $parts = [
            $result->timeline->largadaMs,
            $result->timeline->encerramentoMs,
            $result->timeline->redFlagIntervals,
            $result->timeline->pitIntervals,
        ];
        foreach ($result->laps as $lap) {
            $parts[] = [$lap->carro, $lap->numeroVolta, $lap->tsMs, $lap->fontes, $lap->estado, $lap->elegivelMelhorVolta];
        }
        foreach ($result->flags as $flag) {
            $parts[] = [$flag->id, $flag->autoResolvido, $flag->sugestao];
        }
        foreach ($result->cars as $car) {
            $parts[] = [$car->carro, $car->status];
        }
        return sha1((string) json_encode($parts));
    }
}
