<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Review\ConfirmationNeeded;
use Baja\Contagem\Review\SourceAdmin;
use Baja\Contagem\Review\SourceSettings;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\TestPreset;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemDispositivoQuery;
use InvalidArgumentException;

/**
 * The "Fontes e configurações" page, round 3: switching publishing on,
 * the test preset, and letting a user back in after a device was revoked.
 *
 * These tests submit the page's forms by calling the same functions the
 * page calls, with the fields the form sends.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class SourceAdminSettingsTest extends ReviewDbTestCase
{
    /**
     * SITUATION: the lap counting ran beside the old spreadsheet with the
     * publish mode "desligada". The spreadsheet published 9 laps for car A
     * (the count has 2). A controller opens the settings to change the
     * matching window and, while there, sets the mode to "confirmadas".
     *
     * WHAT WENT WRONG: saving switched publishing on without a word. In
     * the same instant the count overwrote VOLTAS, LAST and BEST of every
     * car, and the start time of the prova, with its own values.
     *
     * EXPECTED: the save is not carried out; the controller is told what
     * switching on overwrites and must confirm. Nothing changed meanwhile:
     * the spreadsheet's 9 laps are still there. With the confirmation the
     * mode changes and the count publishes its 2. Saving while STAYING in
     * "desligada", or with publishing already on, asks nothing.
     */
    public function testSwitchingPublishingOnNeedsConfirmationBecauseItOverwritesTheResults(): void
    {
        $car = self::CAR_A;
        $this->changeSettings(['modo_publicacao' => Settings::MODO_DESLIGADA]);
        $this->startRace(self::RACE_START_MS);
        foreach ([60, 160, 260] as $seconds) {
            $this->allCountersSee($car, $seconds);
        }
        $this->syncAll($this->at(290));
        Clock::freezeAt($this->at(300));
        $this->recompute();
        $this->setManualField($car, 'VOLTAS', 9);

        // Staying switched off: just a save.
        $this->saveSettingsForm(['modo_publicacao' => 'desligada', 'janela_ms' => '15000']);
        $this->assertSame(15000, $this->storedSettings()->janelaMs);

        try {
            $this->saveSettingsForm(['modo_publicacao' => 'confirmadas']);
            $this->fail('publishing was switched on without asking');
        } catch (ConfirmationNeeded $question) {
            $this->assertSame(SourceSettings::CAMPO_CONFIRMAR_PUBLICACAO, $question->campo);
            $this->assertStringContainsString('sobrescritos', $question->getMessage());
        }
        $this->assertSame(Settings::MODO_DESLIGADA, $this->storedSettings()->modoPublicacao);
        $this->assertEquals(9, $this->publishedData($car)['VOLTAS'], 'the spreadsheet\'s value was not touched');

        $this->saveSettingsForm(['modo_publicacao' => 'confirmadas', SourceSettings::CAMPO_CONFIRMAR_PUBLICACAO => '1']);
        $this->assertSame(Settings::MODO_CONFIRMADAS, $this->storedSettings()->modoPublicacao);
        $this->assertEquals(2, $this->publishedData($car)['VOLTAS']);

        // Already on: no question.
        $this->saveSettingsForm(['modo_publicacao' => 'todas']);
        $this->assertSame(Settings::MODO_TODAS, $this->storedSettings()->modoPublicacao);
    }

    /**
     * SITUATION: before the event the organisers rehearse with a simulated
     * race of one-minute laps. The real settings (a 20-second matching
     * window, tuned for laps of four minutes) would merge two laps of such
     * a race into one pass.
     *
     * EXPECTED: "Aplicar predefinição de teste" with an average lap of 60 s
     * scales the time settings down (the matching window becomes much
     * shorter than a lap) and keeps the values that were in force. "Restaurar
     * valores salvos" puts those back exactly, and afterwards there is
     * nothing left to restore. An absurd lap is refused. The preset also
     * switches "Permitir simulação" on (it exists for simulated races), and
     * restoring switches it off again with the rest.
     */
    public function testTheTestPresetScalesTheTimeSettingsAndCanBePutBack(): void
    {
        $this->changeSettings(['janela_ms' => 18000, 'recalculo_s' => 7]);

        $this->newRequest();
        $this->assertFalse($this->service->hasSavedSettings());
        try {
            SourceSettings::applyTestPreset($this->service, ['volta_media_s' => '3']);
            $this->fail('a three-second lap was accepted');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Volta média', $refusal->getMessage());
        }

        SourceSettings::applyTestPreset($this->service, ['volta_media_s' => '60']);

        $preset = $this->storedSettings();
        $this->assertLessThan(18000, $preset->janelaMs);
        $this->assertLessThan(60000 / 2, 2 * $preset->janelaMs, 'two matching windows fit well inside a lap');
        $this->assertTrue($preset->permitirSimulacao);
        $this->assertTrue($this->service->hasSavedSettings());

        SourceSettings::restore($this->service);

        $restored = $this->storedSettings();
        $this->assertSame(18000, $restored->janelaMs);
        $this->assertSame(7, $restored->recalculoS);
        $this->assertFalse($restored->permitirSimulacao);
        $this->assertFalse($this->service->hasSavedSettings());
        try {
            SourceSettings::restore($this->service);
            $this->fail('restored twice');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Não há valores salvos', $refusal->getMessage());
        }
    }

    /**
     * SITUATION: a controller wants a rehearsal with long laps and types
     * 3600 (an hour) as the average lap of the test preset.
     *
     * WHAT WENT WRONG: the form accepted anything from 10 to 3600, and the
     * preset then silently used its own limit: 3600 was applied as 600 and
     * the page said "predefinição aplicada".
     *
     * EXPECTED: the form has the preset's own limits. A value outside them
     * is refused, the sentence names the limits, and nothing is applied.
     * The limits themselves are accepted.
     */
    public function testTheTestPresetRefusesALapOutsideItsLimitsInsteadOfClampingIt(): void
    {
        foreach (['3600', (string) (TestPreset::LONGEST_LAP_S + 1), (string) (TestPreset::SHORTEST_LAP_S - 1), 'abc', ''] as $typed) {
            $this->newRequest();
            try {
                SourceSettings::applyTestPreset($this->service, ['volta_media_s' => $typed]);
                $this->fail('"' . $typed . '" was accepted as the average lap');
            } catch (InvalidArgumentException $refusal) {
                $this->assertStringContainsString('entre ' . TestPreset::SHORTEST_LAP_S . ' e ' . TestPreset::LONGEST_LAP_S, $refusal->getMessage());
            }
            $this->newRequest();
            $this->assertFalse($this->service->hasSavedSettings(), 'nothing was applied for "' . $typed . '"');
        }

        $this->newRequest();
        SourceSettings::applyTestPreset($this->service, ['volta_media_s' => (string) TestPreset::LONGEST_LAP_S]);
        $this->assertTrue($this->storedSettings()->permitirSimulacao);
    }

    /**
     * SITUATION: the test preset is applied (short time settings, and
     * "Permitir simulação" on). After the rehearsal a controller opens the
     * settings form, unticks "Permitir simulação" and saves.
     *
     * WHAT WENT WRONG: that was accepted. The banner went away and the
     * rehearsal's two-second matching window stayed in force for the real
     * race, with nothing left on screen to say so.
     *
     * EXPECTED: the save is refused and the sentence says which button to
     * use instead ("Restaurar valores salvos"). Everything else in the
     * form can still be saved while the preset is applied.
     */
    public function testSimulationCannotBeSwitchedOffAloneWhileThePresetIsApplied(): void
    {
        $this->newRequest();
        SourceSettings::applyTestPreset($this->service, ['volta_media_s' => '60']);
        $presetWindow = $this->storedSettings()->janelaMs;

        try {
            $this->saveSettingsForm(['permitir_simulacao' => '']);
            $this->fail('simulation was switched off with the preset still applied');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Restaurar valores salvos', $refusal->getMessage());
            $this->assertStringContainsString('Nada foi alterado', $refusal->getMessage());
        }
        $this->assertTrue($this->storedSettings()->permitirSimulacao);
        $this->assertSame($presetWindow, $this->storedSettings()->janelaMs);

        $this->saveSettingsForm(['fator_atrasado' => '1,7']);
        $this->assertSame(1.7, $this->storedSettings()->fatorAtrasado);
    }

    /**
     * SITUATION: simulation was switched on by hand (no preset) and a
     * simulated race was played into the prova: its entries are stored with
     * the mode "simulacao". The controller unticks "Permitir simulação".
     *
     * WHAT WENT WRONG: with the setting off, nothing marked those made-up
     * passes any more: they would have counted as the real race.
     *
     * EXPECTED: refused, and the sentence sends the person to "Zerar
     * corrida". Restoring a preset is refused for the same reason. Once the
     * simulated entries are gone the setting can be switched off.
     */
    public function testSimulationCannotBeSwitchedOffWhileSimulatedEntriesExist(): void
    {
        $this->changeSettings(['permitir_simulacao' => true]);
        $entryId = $this->addEntry($this->fontes[0], self::CAR_A, $this->at(60));
        $this->executeSql('UPDATE contagem_passagem SET modo_entrada = ? WHERE passagem_id = ?', ['simulacao', $entryId]);

        try {
            $this->saveSettingsForm(['permitir_simulacao' => '']);
            $this->fail('simulation was switched off with simulated entries in the prova');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Zerar corrida', $refusal->getMessage());
        }
        $this->assertTrue($this->storedSettings()->permitirSimulacao);

        $this->newRequest();
        SourceSettings::applyTestPreset($this->service, ['volta_media_s' => '60']);
        try {
            $this->newRequest();
            SourceSettings::restore($this->service, []);
            $this->fail('the preset was restored with simulated entries in the prova');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('Zerar corrida', $refusal->getMessage());
        }
        $this->assertTrue($this->service->hasSavedSettings(), 'the saved values are still kept');

        $this->executeSql('DELETE FROM contagem_passagem WHERE passagem_id = ?', [$entryId]);
        $this->newRequest();
        SourceSettings::restore($this->service, []);
        $this->assertFalse($this->storedSettings()->permitirSimulacao);
    }

    /**
     * SITUATION: counter "zzt_ana" is linked to Contagem 1 and counting. A
     * controller revokes her phone on the sources page (it was the wrong
     * person's phone, or it was typing nonsense).
     *
     * WHAT WENT WRONG: the phone's page asked for a new token about thirty
     * seconds later, got one, and went on uploading. The sources page gave
     * no hint that "revogar" had not kept anybody out, and no way to let
     * somebody back in on purpose.
     *
     * EXPECTED: after the revocation the sources page lists zzt_ana as
     * blocked on Contagem 1 (and nobody on Contagem 2), with one sentence
     * explaining the rule. Pressing "liberar" unblocks her; the revoked
     * device itself stays revoked. "Liberar" for somebody who is not
     * blocked, or on another source, is refused.
     */
    public function testAUserWhoseDeviceWasRevokedIsListedAsBlockedUntilReleased(): void
    {
        $counter1 = $this->fontes[0];
        $this->linkUser($counter1, 'zzt_ana');
        $this->issueToken($counter1, 'zzt_ana');
        $device = ContagemDispositivoQuery::create()->filterByFonteId($counter1->getFonteId())->findOneByUsuario('zzt_ana');
        $this->assertSame([], SourceSettings::blockedUsers($counter1));

        Clock::freezeAt($this->at(4000));
        $this->newRequest();
        SourceAdmin::revokeDevice($this->service, ['dispositivo_id' => (string) $device->getDispositivoId()]);

        $this->assertSame(['zzt_ana'], SourceSettings::blockedUsers($counter1));
        $this->assertSame([], SourceSettings::blockedUsers($this->fontes[1]));
        $this->assertTrue(DeviceTokens::isBlocked($counter1, 'zzt_ana'));
        $this->assertStringContainsString('liberado aqui', SourceSettings::BLOCK_RULE);

        $form = ['fonte_id' => (string) $counter1->getFonteId(), 'usuario' => 'zzt_ana'];
        try {
            SourceSettings::release($this->service, ['fonte_id' => (string) $this->fontes[1]->getFonteId(), 'usuario' => 'zzt_ana']);
            $this->fail('released on a source where she is not blocked');
        } catch (InvalidArgumentException $refusal) {
            $this->assertStringContainsString('não está bloqueado', $refusal->getMessage());
        }

        Clock::freezeAt($this->at(4100));
        SourceSettings::release($this->service, $form);

        $this->assertSame([], SourceSettings::blockedUsers($counter1));
        $this->assertFalse(DeviceTokens::isBlocked($counter1, 'zzt_ana'));
        TestWorld::forgetLoadedRows();
        $this->assertNotNull(ContagemDispositivoQuery::create()->findPk($device->getDispositivoId())->getRevogadoEmMs(), 'the device stays revoked');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Submits the settings form: every field as the page would send it for
     * the settings in force, with the given ones changed.
     *
     * @param array<string, string> $changes
     */
    private function saveSettingsForm(array $changes): void
    {
        $this->newRequest();
        $form = [];
        foreach ($this->service->getSettings()->toArray() as $key => $value) {
            if (is_bool($value)) {
                if ($value) {
                    $form[$key] = '1';
                }
                continue;
            }
            $form[$key] = $value === null ? '' : str_replace('.', ',', (string) $value);
        }
        SourceAdmin::saveSettings($this->service, $changes + $form);
    }

    private function storedSettings(): Settings
    {
        $this->newRequest();
        return $this->service->getSettings();
    }

    /** A controller links a user to a source on the "Contadores" page. */
    private function linkUser(\Baja\Model\ContagemFonte $fonte, string $username): void
    {
        $this->executeSql(
            'INSERT INTO contagem_fonte_usuario (fonte_id, usuario, adicionado_por, criado_em_ms) VALUES (?, ?, ?, ?)',
            [$fonte->getFonteId(), $username, 'zzt_controle', $this->at(0)]
        );
    }
}
