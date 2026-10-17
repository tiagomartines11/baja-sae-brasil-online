<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\PublishingWouldStart;
use Baja\Contagem\Service\ScorePublisher;
use Baja\Contagem\Service\ScoringFields;
use Baja\Contagem\Service\TestPreset;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemFonte;
use Baja\Model\Prova;
use InvalidArgumentException;
use Propel\Runtime\Propel;

/**
 * The settings half of the "Fontes e configurações" page: saving the
 * settings form, the test preset for rehearsals, and letting a user whose
 * device was revoked back in.
 */
final class SourceSettings
{
    /** Form field: "I was told switching publishing on overwrites the official results, and said yes". */
    public const CAMPO_CONFIRMAR_PUBLICACAO = 'confirmar_publicacao';

    /** What switching publishing on does, said the same way wherever it is asked. */
    private const PUBLISHING_STARTS = 'a contagem passa a gravar na pontuação oficial: VOLTAS, LAST e BEST de todos os carros '
        . 'e o horário de largada da prova são sobrescritos agora com os valores da contagem, no lugar do que a planilha publicou. '
        . 'Não há como desfazer pela tela.';

    /** The one sentence that explains the block to a controller. */
    public const BLOCK_RULE = 'Quem teve um dispositivo revogado não recebe novo acesso a esta fonte até ser vinculado de novo '
        . 'em Usuários ou liberado aqui; sem isso o aparelho revogado pediria um acesso novo sozinho, meio minuto depois.';

    /**
     * @param array<string, mixed> $post
     * @throws ConfirmationNeeded when publishing is being switched on and the person was not asked yet
     * @throws InvalidArgumentException naming the bad field
     */
    public static function save(ContagemService $service, array $post): void
    {
        $countingIds = [];
        $activeCountingIds = [];
        foreach (SourceAdmin::sources($service->getProva()) as $fonte) {
            if ($fonte->getTipo() === Source::TIPO_TOTAIS) {
                continue;
            }
            // Nothing an observer records counts: as the primary source it
            // would take every lap away from every car.
            if ($fonte->getPapel() === Source::PAPEL_OBSERVADORA) {
                $postedPrimary = is_string($post['fonte_principal_id'] ?? null) ? trim($post['fonte_principal_id']) : '';
                if ($postedPrimary === (string) $fonte->getFonteId()) {
                    throw new InvalidArgumentException(sprintf(
                        'A fonte principal não pode ser "%s": ela é observadora e nada do que registra conta. Promova-a a votante antes, ou escolha outra. Nada foi alterado.',
                        $fonte->getNome()
                    ));
                }
                continue;
            }
            $countingIds[] = (int) $fonte->getFonteId();
            if ($fonte->getAtiva()) {
                $activeCountingIds[] = (int) $fonte->getFonteId();
            }
        }
        $current = $service->getSettings();
        $settings = SettingsForm::fromPost(
            $post,
            $countingIds,
            $activeCountingIds,
            array_keys(self::classificationChoices($service->getProva())),
            self::refusedClassificationFields($service->getProva(), $current->campoClassificacao)
        );

        $switchesSimulationOff = $current->permitirSimulacao && !$settings->permitirSimulacao;
        if ($switchesSimulationOff) {
            // saveSettings() refuses both of these. Said here in the page's
            // own words, each with the button that gets the person out.
            if ($service->hasSavedSettings()) {
                throw new InvalidArgumentException(
                    'A predefinição de teste está aplicada: desligar só "Permitir simulação" deixaria os tempos do ensaio valendo numa corrida de verdade. '
                        . 'Use "Restaurar valores salvos", no fim desta página: ele devolve os tempos anteriores e desliga a simulação. Nada foi alterado.'
                );
            }
            if ($service->hasSimulatedRows()) {
                throw new InvalidArgumentException(
                    'Esta prova tem registros de simulação. Com a simulação desligada eles passariam a contar como uma corrida de verdade. '
                        . 'Use "Zerar corrida" em Controle de corrida e depois desligue a simulação. Nada foi alterado.'
                );
            }
        }

        $switchesPublishingOn = !$current->publishesToScoring() && $settings->publishesToScoring();
        if ($switchesPublishingOn && ($post[self::CAMPO_CONFIRMAR_PUBLICACAO] ?? '') !== '1') {
            // Until now the spreadsheet (or a person) wrote these values.
            // From this save on the count does, at once and for every car.
            throw new ConfirmationNeeded(
                'A publicação está DESLIGADA. Ao ligá-la, ' . self::PUBLISHING_STARTS,
                self::CAMPO_CONFIRMAR_PUBLICACAO,
                'Ligar a publicação e sobrescrever'
            );
        }
        // saveSettings() takes the lock and recounts the race by itself.
        $service->saveSettings($settings);
    }

    /**
     * The input fields of the prova that may be named as the classification
     * field: every field people type. The three the count writes itself
     * cannot say whether a car takes part. A field the scoring formula
     * reads is listed too, marked as such: the page shows it disabled, so
     * that nobody wonders where DESCLAS went. The one already in force
     * ($inForce) stays selectable (see refusedClassificationFields()), so
     * its label asks for it to be replaced instead of saying it cannot be
     * chosen.
     *
     * @return array<string, string> field code => label for the list
     */
    public static function classificationChoices(Prova $prova, ?string $inForce = null): array
    {
        $readByScoring = ScoringFields::readByScoring($prova);
        $choices = [];
        foreach ($prova->getParamsInputs() as $field) {
            $code = (string) $field->getCode();
            if (in_array($code, ScorePublisher::ENGINE_FIELDS, true)) {
                continue;
            }
            $choices[$code] = $code . ' (' . $field->getName() . ')';
            if (in_array($code, $readByScoring, true)) {
                $choices[$code] .= $code === $inForce
                    ? ' — lido pela fórmula de pontuação: troque por um campo próprio (APTO)'
                    : ' — usado na fórmula de pontuação, não pode ser escolhido';
            }
        }
        return $choices;
    }

    /**
     * The fields the settings form refuses as the classification field:
     * those the scoring formula reads. The one already in force is let
     * through, so that a prova already in that state can still change its
     * other settings during a race; the pages warn about it loudly instead
     * (see ScoringFields::classificationConflict()).
     *
     * @return string[]
     */
    public static function refusedClassificationFields(Prova $prova, ?string $inForce): array
    {
        return array_values(array_diff(ScoringFields::readByScoring($prova), $inForce === null ? [] : [$inForce]));
    }

    /**
     * "Predefinição de teste": the time settings scaled to a short lap, for
     * a rehearsal with a simulated race. The values in force are kept and
     * come back with restore().
     *
     * @param array<string, mixed> $post
     */
    public static function applyTestPreset(ContagemService $service, array $post): int
    {
        // The limits are the preset's own. A value outside them used to be
        // accepted and silently replaced by the nearest limit: 3600 s was
        // applied as 600 s and the page said "aplicada".
        $lapS = filter_var($post['volta_media_s'] ?? null, FILTER_VALIDATE_INT);
        if ($lapS === false || $lapS < TestPreset::SHORTEST_LAP_S || $lapS > TestPreset::LONGEST_LAP_S) {
            throw new InvalidArgumentException(
                'Volta média: informe os segundos de uma volta, entre ' . TestPreset::SHORTEST_LAP_S . ' e ' . TestPreset::LONGEST_LAP_S
                    . '. Nada foi alterado.'
            );
        }
        $service->applyTestPreset($lapS);
        return $lapS;
    }

    /**
     * "Restaurar valores salvos": the time settings from before the preset
     * come back and simulation is switched off.
     *
     * @param array<string, mixed> $post
     * @throws ConfirmationNeeded when restoring would switch publishing on and the person was not asked yet
     * @throws InvalidArgumentException when no saved values exist, or simulated records are still in the prova
     */
    public static function restore(ContagemService $service, array $post = []): void
    {
        $confirmed = ($post[self::CAMPO_CONFIRMAR_PUBLICACAO] ?? '') === '1';
        try {
            $restored = $service->restoreSavedSettings($confirmed);
        } catch (PublishingWouldStart $wouldPublish) {
            // The same question as when leaving "desligada" by hand: a
            // rehearsal's counts must not reach the official results unasked.
            throw new ConfirmationNeeded(
                'Restaurar os valores salvos LIGA a publicação: ' . self::PUBLISHING_STARTS
                    . ' Se a prova ainda tem dados de um ensaio, use "Zerar corrida" antes.',
                self::CAMPO_CONFIRMAR_PUBLICACAO,
                'Restaurar e ligar a publicação'
            );
        }
        if (!$restored) {
            throw new InvalidArgumentException('Não há valores salvos para restaurar: nenhuma predefinição de teste está aplicada.');
        }
    }

    /**
     * Users who are kept out of a source because a device of theirs on it
     * was revoked.
     *
     * @return string[] usernames, in alphabetical order
     */
    public static function blockedUsers(ContagemFonte $fonte): array
    {
        $statement = Propel::getWriteConnection('resultados')->prepare(
            'SELECT DISTINCT usuario FROM contagem_dispositivo
              WHERE fonte_id = ? AND usuario IS NOT NULL AND revogado_em_ms IS NOT NULL
              ORDER BY usuario'
        );
        $statement->execute([$fonte->getFonteId()]);

        $blocked = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $usuario) {
            if (DeviceTokens::isBlocked($fonte, (string) $usuario)) {
                $blocked[] = (string) $usuario;
            }
        }
        return $blocked;
    }

    /**
     * "Liberar": lets a blocked user get a token for the source again.
     *
     * @param array<string, mixed> $post
     */
    public static function release(ContagemService $service, array $post): void
    {
        $fonte = SourceAdmin::requireSource($service->getProva(), $post['fonte_id'] ?? null, true);
        $usuario = is_string($post['usuario'] ?? null) ? trim($post['usuario']) : '';
        if ($usuario === '' || !in_array($usuario, self::blockedUsers($fonte), true)) {
            throw new InvalidArgumentException('Este usuário não está bloqueado nesta fonte.');
        }
        DeviceTokens::release($fonte, $usuario);
    }
}
