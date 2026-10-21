<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use InvalidArgumentException;

/**
 * The "Fontes e configurações" page: switching a source (a counter) on and
 * off, and what must not be done to the source the count depends on.
 *
 * These tests submit the page's forms by calling SourceAdmin::update() with
 * the fields the form sends. A refused change throws
 * InvalidArgumentException with the sentence shown to the controller.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class SourceAdminRulesTest extends ReviewDbTestCase
{
    /**
     * SITUATION: car A crosses three times in the first five minutes. Each
     * time counters 2 and 3 record it and counter 1 misses it. Two counters
     * agree, so the passes count: the pass zero and two laps.
     * At minute 10 counter 3 goes home, and the controller unticks "ativa"
     * on Contagem 3 so that the system stops waiting for it.
     *
     * WHAT WENT WRONG: switching a source off made the system ignore
     * everything that source had EVER recorded. Each of those three passes
     * was left with one counter only, stopped counting, and car A dropped
     * from 2 laps to 0 in the official results, in the middle of the race.
     *
     * EXPECTED: the moment of switching off is stored (desativada_em_ms).
     * What the source recorded before that moment keeps counting: car A
     * still has 2 laps. Ticking "ativa" again clears the moment.
     */
    public function testSwitchingASourceOffKeepsWhatItRecordedBefore(): void
    {
        $car = self::CAR_A;
        $counter3 = $this->fontes[2];
        $this->startRace(self::RACE_START_MS);
        foreach ([60, 160, 260] as $seconds) {
            $this->addEntry($this->fontes[1], $car, $this->at($seconds));
            $this->addEntry($counter3, $car, $this->at($seconds + 1));
        }
        $this->syncAll($this->at(590));
        Clock::freezeAt($this->at(600));
        $this->recompute();
        $this->assertEquals(2, $this->publishedData($car)['VOLTAS']);

        $this->submitSourceForm($counter3, ['ativa' => '']);

        $stored = $this->reload($counter3);
        $this->assertFalse((bool) $stored->getAtiva());
        $this->assertSame($this->at(600), (int) $stored->getDesativadaEmMs());
        $this->assertEquals(2, $this->publishedData($car)['VOLTAS'], 'the laps recorded before the switch-off still count');

        $this->submitSourceForm($counter3, ['ativa' => '1']);
        $stored = $this->reload($counter3);
        $this->assertTrue((bool) $stored->getAtiva());
        $this->assertNull($stored->getDesativadaEmMs());
    }

    /**
     * SITUATION: the publish mode is "fonte principal" with Contagem 1 as
     * the primary source: the official count is simply what Contagem 1
     * recorded. Somebody unticks "ativa" on Contagem 1, or changes its type
     * to "totais" (a list of paper totals).
     *
     * WHAT WENT WRONG: both were accepted. From that moment no new pass
     * could count for any car, and nothing on the screen said why.
     *
     * EXPECTED: both are refused, with a sentence that says what to change
     * first (the primary source or the publish mode). Renaming the source,
     * which is harmless, still works. In the default mode ("confirmadas")
     * the same source can be switched off freely.
     */
    public function testThePrimarySourceCannotBeSwitchedOffOrRetypedWhileTheCountDependsOnIt(): void
    {
        $primary = $this->fontes[0];
        $this->service->saveSettings(Settings::fromArray([
            'modo_publicacao' => Settings::MODO_FONTE_PRINCIPAL,
            'fonte_principal_id' => $primary->getFonteId(),
        ]));

        $this->assertStringContainsString('fonte principal', $this->refusalOf($primary, ['ativa' => '']));
        $this->assertStringContainsString('mudar o tipo', $this->refusalOf($primary, ['tipo' => 'totais']));
        $stored = $this->reload($primary);
        $this->assertTrue((bool) $stored->getAtiva());
        $this->assertSame('manual', $stored->getTipo());

        $this->submitSourceForm($primary, ['nome' => 'Contagem principal']);
        $this->assertSame('Contagem principal', $this->reload($primary)->getNome());

        $this->newRequest();
        $this->service->saveSettings(Settings::fromArray(['modo_publicacao' => Settings::MODO_CONFIRMADAS]));
        $this->submitSourceForm($primary, ['ativa' => '']);
        $this->assertFalse((bool) $this->reload($primary)->getAtiva());
    }

    /**
     * Submits the "Salvar" form of one source. Fields not given keep the
     * value the source has now, as the form on the page would send them.
     *
     * @param array<string, string> $changes
     */
    private function submitSourceForm(ContagemFonte $fonte, array $changes): void
    {
        $current = $this->reload($fonte);
        $form = $changes + [
            'fonte_id' => (string) $current->getFonteId(),
            'nome' => (string) $current->getNome(),
            'tipo' => (string) $current->getTipo(),
            'qualidade_tempo' => (string) $current->getQualidadeTempo(),
            'ordem' => (string) $current->getOrdem(),
            'ativa' => $current->getAtiva() ? '1' : '',
        ];
        $this->newRequest();
        SourceAdmin::update($this->service, $form);
    }

    /**
     * @param array<string, string> $changes
     */
    private function refusalOf(ContagemFonte $fonte, array $changes): string
    {
        try {
            $this->submitSourceForm($fonte, $changes);
        } catch (InvalidArgumentException $refusal) {
            return $refusal->getMessage();
        }
        $this->fail('the change was accepted, but it should have been refused');
    }

    /** The source as it is in the database right now. */
    private function reload(ContagemFonte $fonte): ContagemFonte
    {
        TestWorld::forgetLoadedRows();
        return ContagemFonteQuery::create()->findPk($fonte->getFonteId());
    }
}
