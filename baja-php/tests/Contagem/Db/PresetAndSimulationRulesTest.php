<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\PublicFeed;
use Baja\Contagem\Service\RaceContext;
use Baja\Contagem\Service\RedisClient;
use Baja\Contagem\Service\TestPreset;

/**
 * Getting into and, above all, out of a rehearsal safely.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * For rehearsals a prova can be put in a special state:
 *
 *   - "Permitir simulação" lets the count pages inject a made-up race;
 *   - the "Predefinição de teste" scales the time settings down (a matching
 *     window of one second instead of twenty) so that a race with laps of a
 *     few seconds behaves like a real one. The real values are kept aside.
 *
 * Both are dangerous to forget. A real race counted with a one-second
 * matching window would be wrong from the first lap, and made-up passes
 * left in a prova would be published as results. These tests are the rules
 * that make the way out of a rehearsal safe:
 *
 *   - leaving the preset puts back ONLY the time settings, and switches
 *     simulation off;
 *   - simulation cannot be switched off while made-up passes exist, nor
 *     while the scaled-down timings are still in place.
 */
final class PresetAndSimulationRulesTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Clock::freezeAt($this->at(600));
    }

    /** A made-up pass, uploaded the way the simulation does. */
    private function uploadOneSimulatedPass(): void
    {
        $token = $this->issueToken($this->fontes[0]);
        $this->upload($token, [['mode' => 'simulacao'] + $this->entryPayload(self::newUuid(), self::CAR_A, $this->at(120))]);
    }

    /**
     * SITUATION: the test preset is applied for 15-second laps. While it is
     * applied, the person in charge sets the prova up for race day on the
     * same page: publishing off (a shadow race), the fueling station on, a
     * classification field. Then they press "Restaurar configurações
     * salvas".
     *
     * WHAT WENT WRONG BEFORE: restoring replaced EVERY setting by the copy
     * taken when the preset was applied. The three edits were silently
     * undone, and the publish mode went back to "confirmadas": a race meant
     * to run in the shadow of the spreadsheets would have published.
     *
     * EXPECTED: the time settings are the real ones again (matching window
     * 20 s), simulation is off, and the three edits are still there.
     */
    public function testLeavingThePresetRestoresOnlyTheTimeSettings(): void
    {
        $this->service->applyTestPreset(15);
        $this->changeSettings(['modo_publicacao' => 'desligada', 'abastecimento' => true, 'campo_classificacao' => 'APTO']);
        $this->assertSame(1250, $this->service->getSettings()->janelaMs, 'the preset is in force');
        $this->assertTrue($this->service->hasSavedSettings());

        $restored = $this->service->restoreSavedSettings();

        $this->newRequest();
        $settings = $this->service->getSettings();
        $this->assertTrue($restored);
        $this->assertSame(20000, $settings->janelaMs);
        $this->assertSame(5000, $settings->duplicataMs);
        $this->assertFalse($settings->permitirSimulacao);
        $this->assertSame('desligada', $settings->modoPublicacao);
        $this->assertTrue($settings->abastecimento);
        $this->assertSame('APTO', $settings->campoClassificacao);
        $this->assertFalse($this->service->hasSavedSettings());
    }

    /**
     * SITUATION: the preset is applied (so simulation is on and the
     * matching window is about a second). Somebody unticks "Permitir
     * simulação" on the settings form and saves.
     *
     * WHAT WENT WRONG BEFORE: it was saved. The banner disappeared, and
     * the prova was left with rehearsal timings and nothing on screen to
     * say so.
     *
     * EXPECTED: refused, with a sentence that says what to press instead.
     * Nothing changes.
     */
    public function testSimulationCannotBeSwitchedOffWhileThePresetIsApplied(): void
    {
        $this->service->applyTestPreset(15);

        try {
            $this->changeSettings(['permitir_simulacao' => false]);
            $this->fail('Switching simulation off was accepted.');
        } catch (\InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Restaurar configurações salvas', $refusal->getMessage());
        }

        $this->newRequest();
        $this->assertTrue($this->service->getSettings()->permitirSimulacao);
        $this->assertSame(1250, $this->service->getSettings()->janelaMs);
    }

    /**
     * SITUATION: a rehearsal was run: the prova holds simulated passes.
     * Somebody tries to leave the rehearsal state, by restoring the
     * settings or by unticking the setting, without wiping the race.
     *
     * EXPECTED: both are refused and name "Zerar corrida". With simulation
     * off those passes would be counted as a real race, and nothing would
     * mark them any more. After the race is wiped, restoring works.
     */
    public function testSimulationCannotBeLeftWhileSimulatedPassesExist(): void
    {
        $this->service->applyTestPreset(15);
        $this->startRace($this->at(0));
        $this->uploadOneSimulatedPass();
        $this->newRequest();
        $this->assertTrue($this->service->hasSimulatedRows());

        foreach ([fn() => $this->service->restoreSavedSettings(), fn() => $this->changeSettings(['permitir_simulacao' => false])] as $attempt) {
            try {
                $attempt();
                $this->fail('Leaving the simulation was accepted with simulated passes in the prova.');
            } catch (\InvalidArgumentException $refusal) {
                $this->assertStringContainsString('Zerar corrida', $refusal->getMessage());
            }
            $this->newRequest();
        }
        $this->assertTrue($this->service->getSettings()->permitirSimulacao);

        $this->service->resetRace(self::USER_PREFIX . 'admin');
        $this->newRequest();
        $this->assertFalse($this->service->hasSimulatedRows());
        $this->assertTrue($this->service->restoreSavedSettings());
    }

    /**
     * SITUATION: a rehearsal is being prepared, simulation is on.
     *
     * EXPECTED: the public feed (what broadcast graphics read) says so,
     * so that nobody puts a made-up race on air as a real one; and it
     * stops saying so when simulation is off.
     */
    public function testThePublicFeedSaysWhenARaceMayBeSimulated(): void
    {
        $feed = fn(): array => json_decode((string) PublicFeed::jsonFor(self::EVENTO_ID, self::PROVA_ID), true);
        $forgetFeedCache = function (): void {
            RedisClient::get()?->del('contagem:' . self::EVENTO_ID . ':' . self::PROVA_ID . ':feed');
        };

        $this->assertFalse($feed()['simulacao']);

        $this->changeSettings(['permitir_simulacao' => true]);
        $forgetFeedCache();
        $this->assertTrue($feed()['simulacao']);
    }

    /**
     * SITUATION: the preset for a lap of 15 seconds.
     *
     * EXPECTED: every time setting keeps the proportion it has to a real
     * lap of four minutes (a sixteenth here), down to sensible floors, and
     * the window that warns of a wide group never exceeds the matching
     * window itself.
     */
    public function testThePresetScalesEveryTimeSettingToTheLap(): void
    {
        $preset = TestPreset::forLap(15);

        $this->assertSame(1250, $preset->janelaMs);
        $this->assertSame(400, $preset->duplicataMs);
        $this->assertSame(60, $preset->janelaRitmoS);
        $this->assertSame(3, $preset->recalculoS);
        $this->assertSame(600, $preset->dispersaoAlertaMs);
        $this->assertLessThanOrEqual($preset->janelaMs, $preset->dispersaoAlertaMs);
    }

    /**
     * SITUATION: phones' heartbeats read the "race context" from a cache.
     * One of them finds the cache empty and reads the database; in that
     * instant race control starts the race and writes the new context to
     * the cache; then the heartbeat stores what IT read (no start yet).
     *
     * WHAT WENT WRONG BEFORE: the heartbeat's older copy overwrote the
     * newer one, and phones showed "waiting for the start" for half a
     * minute into the race.
     *
     * EXPECTED: a request that fills an empty cache only stores its copy
     * if nobody stored one meanwhile; otherwise it uses the one that is
     * there. Played here by calling that step directly while the cache
     * already holds a newer context.
     */
    public function testFillingTheContextCacheNeverOverwritesANewerContext(): void
    {
        $redis = RedisClient::get();
        if ($redis === null) {
            $this->markTestSkipped('Redis is not available in this environment.');
        }
        $context = new RaceContext(self::EVENTO_ID, self::PROVA_ID);
        // The heartbeat has read the database: no start. Now the start is recorded and cached.
        $this->startRace($this->at(0));
        $this->assertSame($this->at(0), $context->get()['largada_ms']);
        // The race is wiped behind the cache's back, to make "what the database says" differ from "what is cached".
        $this->executeSql('DELETE FROM contagem_ocorrencia WHERE evento_id = ? AND prova_id = ? AND tipo = ?', [self::EVENTO_ID, self::PROVA_ID, Ocorrencia::LARGADA]);

        $fill = new \ReflectionMethod($context, 'fillIfStillEmpty');
        $fill->setAccessible(true);
        $used = $fill->invoke($context);

        $this->assertSame($this->at(0), $used['largada_ms'], 'the copy already in the cache is the one used');
        $this->assertSame($this->at(0), $context->get()['largada_ms'], 'and it was not overwritten');
    }
}
