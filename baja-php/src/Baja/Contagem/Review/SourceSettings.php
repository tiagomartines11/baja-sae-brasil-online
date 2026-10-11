<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Service\ScorePublisher;
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

    /** Shortest and longest average lap the test preset accepts, in seconds. */
    private const MIN_TEST_LAP_S = 10;
    private const MAX_TEST_LAP_S = 3600;

    /** The one sentence that explains the block to a controller. */
    public const BLOCK_RULE = 'Quem teve um dispositivo revogado não recebe novo acesso a esta fonte até ser vinculado de novo '
        . 'em Contadores ou liberado aqui; sem isso o aparelho revogado pediria um acesso novo sozinho, meio minuto depois.';

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
            $countingIds[] = (int) $fonte->getFonteId();
            if ($fonte->getAtiva()) {
                $activeCountingIds[] = (int) $fonte->getFonteId();
            }
        }
        $settings = SettingsForm::fromPost($post, $countingIds, $activeCountingIds, array_keys(self::classificationChoices($service->getProva())));

        $switchesPublishingOn = !$service->getSettings()->publishesToScoring() && $settings->publishesToScoring();
        if ($switchesPublishingOn && ($post[self::CAMPO_CONFIRMAR_PUBLICACAO] ?? '') !== '1') {
            // Until now the spreadsheet (or a person) wrote these values.
            // From this save on the count does, at once and for every car.
            throw new ConfirmationNeeded(
                'A publicação está DESLIGADA. Ao ligá-la, a contagem passa a gravar na pontuação oficial: VOLTAS, LAST e BEST de todos os carros '
                    . 'e o horário de largada da prova são sobrescritos agora com os valores da contagem, no lugar do que a planilha publicou. '
                    . 'Não há como desfazer pela tela.',
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
     * cannot say whether a car takes part.
     *
     * @return array<string, string> field code => label for the list
     */
    public static function classificationChoices(Prova $prova): array
    {
        $choices = [];
        foreach ($prova->getParamsInputs() as $field) {
            $code = (string) $field->getCode();
            if (!in_array($code, ScorePublisher::ENGINE_FIELDS, true)) {
                $choices[$code] = $code . ' (' . $field->getName() . ')';
            }
        }
        return $choices;
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
        $lapS = filter_var($post['volta_media_s'] ?? null, FILTER_VALIDATE_INT);
        if ($lapS === false || $lapS < self::MIN_TEST_LAP_S || $lapS > self::MAX_TEST_LAP_S) {
            throw new InvalidArgumentException(
                'Volta média: informe os segundos de uma volta, entre ' . self::MIN_TEST_LAP_S . ' e ' . self::MAX_TEST_LAP_S . '.'
            );
        }
        $service->applyTestPreset($lapS);
        return $lapS;
    }

    /** @throws InvalidArgumentException when no saved values exist */
    public static function restore(ContagemService $service): void
    {
        if (!$service->restoreSavedSettings()) {
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
