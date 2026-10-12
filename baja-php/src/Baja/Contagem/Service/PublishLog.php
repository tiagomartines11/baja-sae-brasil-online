<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Model\Prova;

/**
 * Remembers what publishing to the scoring system last did, and when.
 *
 * Two things other parts ask about: "what did the publish of this request
 * do?" (kept in memory, for tests and for the answer to a decision) and
 * "when were scores last written?" (kept in Redis, shown on the review
 * screen as "publicado há N s").
 *
 * It also owns the one other column lap counting writes outside its own
 * tables: prova.tempo, the start of the race in Unix seconds, from which
 * the public results pages and the overlay take their race clock.
 */
final class PublishLog
{
    private Prova $prova;
    private ?PublishOutcome $lastOutcome = null;

    public function __construct(Prova $prova)
    {
        $this->prova = $prova;
    }

    /**
     * @param bool $always record the time even when no value changed ("Republicar tudo", "Zerar corrida")
     */
    public function note(PublishOutcome $outcome, bool $always = false): void
    {
        $this->lastOutcome = $outcome;
        if (!$always && !$outcome->anythingChanged()) {
            return;
        }
        $redis = RedisClient::get();
        if ($redis === null) {
            return;
        }
        try {
            $redis->set($this->key(), (string) Clock::nowMs());
        } catch (\Throwable $error) {
            error_log('Contagem\PublishLog: could not record publish time: ' . $error->getMessage());
        }
    }

    /** What the most recent publish through this object did; null if none ran yet. */
    public function lastOutcome(): ?PublishOutcome
    {
        return $this->lastOutcome;
    }

    /** When scores were last written to the scoring system; null if never (or unknown). */
    public function lastPublishedAtMs(): ?int
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            return null;
        }
        try {
            $value = $redis->get($this->key());
        } catch (\Throwable $error) {
            return null;
        }
        return is_string($value) && $value !== '' ? (int) $value : null;
    }

    /**
     * Sets prova.tempo to the start in force (or to nothing when the race
     * has no start any more). Only to be called while the prova publishes.
     */
    public function publishStartTime(EngineResult $result): void
    {
        $startMs = $result->timeline->largadaMs;
        $this->prova->setTempo($startMs === null ? null : intdiv($startMs, 1000));
        $this->prova->save();
    }

    private function key(): string
    {
        return 'contagem:' . $this->prova->getEventoId() . ':' . $this->prova->getProvaId() . ':publicado_em_ms';
    }
}
