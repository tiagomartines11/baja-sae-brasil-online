<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\EquipeQuery;
use Baja\Model\Prova;
use InvalidArgumentException;

/**
 * The actions of the sources page: create and edit sources, revoke devices,
 * create tokens for automated sources, save settings, import paper totals.
 *
 * Every action that can change the count ends by rerunning the engine under
 * the per-prova lock, so the change is official the moment the page reloads.
 */
final class SourceAdmin
{
    public const TYPE_LABELS = [
        Source::TIPO_MANUAL => 'Manual (pessoa contando)',
        Source::TIPO_AUTOMATICA => 'Automática (sistema externo)',
        Source::TIPO_TOTAIS => 'Totais importados (papel)',
    ];

    public const TIMING_LABELS = [
        Source::TEMPO_HUMANA => 'Humana (tempos combinados pela mediana)',
        Source::TEMPO_PRECISA => 'Precisa (o tempo desta fonte vale como está)',
    ];

    private const MAX_NAME_LENGTH = 45;
    private const MAX_LABEL_LENGTH = 100;
    private const MAX_UPLOAD_BYTES = 200000;

    /**
     * @return ContagemFonte[] the prova's sources in display order, without the pit log's internal one
     */
    public static function sources(Prova $prova): array
    {
        $all = ContagemFonteQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->orderByOrdem()
            ->orderByFonteId()
            ->find();

        $sources = [];
        foreach ($all as $fonte) {
            if ($fonte->getTipo() !== Source::TIPO_BOX) {
                $sources[] = $fonte;
            }
        }
        return $sources;
    }

    /** @return ContagemFonte[] only the sources people count on */
    public static function manualSources(Prova $prova, bool $onlyActive = false): array
    {
        return array_values(array_filter(
            self::sources($prova),
            fn(ContagemFonte $fonte) => $fonte->getTipo() === Source::TIPO_MANUAL && (!$onlyActive || $fonte->getAtiva())
        ));
    }

    /** The pit log's internal source, when a pit-log device was ever opened. */
    public static function boxSource(Prova $prova): ?ContagemFonte
    {
        return ContagemFonteQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->filterByTipo(Source::TIPO_BOX)
            ->findOne();
    }

    /**
     * A source of this prova by the id that came in a form. The id is never
     * trusted on its own: a source of another race must not be reachable.
     *
     * @param mixed $postedId
     * @throws InvalidArgumentException
     */
    public static function requireSource(Prova $prova, $postedId, bool $allowBox = false): ContagemFonte
    {
        $id = filter_var($postedId, FILTER_VALIDATE_INT);
        $fonte = $id === false ? null : ContagemFonteQuery::create()
            ->filterByEventoId($prova->getEventoId())
            ->filterByProvaId($prova->getProvaId())
            ->findOneByFonteId($id);
        if ($fonte === null || (!$allowBox && $fonte->getTipo() === Source::TIPO_BOX)) {
            throw new InvalidArgumentException('Fonte não encontrada nesta prova.');
        }
        return $fonte;
    }

    /** @return ContagemDispositivo[] newest first */
    public static function devices(ContagemFonte $fonte): array
    {
        $rows = ContagemDispositivoQuery::create()
            ->filterByFonteId($fonte->getFonteId())
            ->orderByDispositivoId('desc')
            ->find();
        return iterator_to_array($rows, false);
    }

    /** @param array<string, mixed> $post */
    public static function create(ContagemService $service, array $post): void
    {
        $prova = $service->getProva();
        $fonte = new ContagemFonte();
        $fonte->setEventoId($prova->getEventoId());
        $fonte->setProvaId($prova->getProvaId());
        $fonte->setAtiva(true);
        // New sources go to the end of the list.
        $fonte->setOrdem(count(self::sources($prova)) + 1);
        self::applyEditableFields($fonte, $post);
        $fonte->save();
        self::rerunEngine($service);
    }

    /** @param array<string, mixed> $post */
    public static function update(ContagemService $service, array $post): void
    {
        $fonte = self::requireSource($service->getProva(), $post['fonte_id'] ?? null);
        self::applyEditableFields($fonte, $post);

        $order = filter_var($post['ordem'] ?? null, FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 999) {
            throw new InvalidArgumentException('Ordem inválida: use um número entre 0 e 999.');
        }
        $fonte->setOrdem($order);
        // Deactivating keeps every entry the source recorded; the engine just stops using them.
        $fonte->setAtiva(($post['ativa'] ?? '') === '1');
        $fonte->save();
        self::rerunEngine($service);
    }

    /** @param array<string, mixed> $post */
    public static function revokeDevice(ContagemService $service, array $post): void
    {
        $id = filter_var($post['dispositivo_id'] ?? null, FILTER_VALIDATE_INT);
        $device = $id === false ? null : ContagemDispositivoQuery::create()->findOneByDispositivoId($id);
        if ($device === null) {
            throw new InvalidArgumentException('Dispositivo não encontrado.');
        }
        // Throws when the device's source is not of this prova.
        self::requireSource($service->getProva(), $device->getFonteId(), true);
        DeviceTokens::revoke($device);
        self::rerunEngine($service);
    }

    /**
     * @param array<string, mixed> $post
     * @return string the plain token; it cannot be shown again
     */
    public static function createApiToken(ContagemService $service, array $post): string
    {
        $fonte = self::requireSource($service->getProva(), $post['fonte_id'] ?? null);
        if ($fonte->getTipo() !== Source::TIPO_AUTOMATICA) {
            throw new InvalidArgumentException('Tokens de API só existem para fontes automáticas.');
        }
        $label = self::cleanText($post['rotulo'] ?? '', self::MAX_LABEL_LENGTH);
        return DeviceTokens::issue($fonte, null, $label !== '' ? $label : 'API');
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|null $upload the $_FILES entry, when a file was sent
     * @return int how many cars were imported
     */
    public static function importTotals(ContagemService $service, array $post, ?array $upload): int
    {
        $prova = $service->getProva();
        $fonte = self::requireSource($prova, $post['fonte_id'] ?? null);
        if ($fonte->getTipo() !== Source::TIPO_TOTAIS) {
            throw new InvalidArgumentException('Só é possível importar totais em uma fonte do tipo "totais".');
        }

        $text = is_string($post['texto'] ?? null) ? $post['texto'] : '';
        if ($upload !== null && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $text = self::readUpload($upload);
        }

        $validCars = [];
        foreach (EquipeQuery::create()->filterByEventoId($prova->getEventoId())->find() as $equipe) {
            $validCars[] = (int) $equipe->getEquipeId();
        }

        $totals = TotalsImport::parse($text, $validCars);
        TotalsImport::replace($fonte, $totals);
        self::rerunEngine($service);
        return count($totals);
    }

    /** @param array<string, mixed> $post */
    public static function saveSettings(ContagemService $service, array $post): void
    {
        $countingIds = [];
        foreach (self::sources($service->getProva()) as $fonte) {
            if ($fonte->getTipo() !== Source::TIPO_TOTAIS) {
                $countingIds[] = (int) $fonte->getFonteId();
            }
        }
        $service->saveSettings(SettingsForm::fromPost($post, $countingIds));
        self::rerunEngine($service);
    }

    public static function rerunEngine(ContagemService $service): void
    {
        $service->withLock(function () use ($service): void {
            $service->recomputeAndPublish();
        });
    }

    /** @param array<string, mixed> $post */
    private static function applyEditableFields(ContagemFonte $fonte, array $post): void
    {
        $name = self::cleanText($post['nome'] ?? '', self::MAX_NAME_LENGTH);
        if ($name === '') {
            throw new InvalidArgumentException('Informe o nome da fonte.');
        }
        $type = $post['tipo'] ?? '';
        if (!is_string($type) || !isset(self::TYPE_LABELS[$type])) {
            throw new InvalidArgumentException('Tipo de fonte inválido.');
        }
        $timing = $post['qualidade_tempo'] ?? '';
        if (!is_string($timing) || !isset(self::TIMING_LABELS[$timing])) {
            throw new InvalidArgumentException('Qualidade de tempo inválida.');
        }
        $fonte->setNome($name);
        $fonte->setTipo($type);
        $fonte->setQualidadeTempo($timing);
    }

    /** @param mixed $value */
    private static function cleanText($value, int $maxLength): string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        return mb_substr($text, 0, $maxLength);
    }

    /** @param array<string, mixed> $upload */
    private static function readUpload(array $upload): string
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])) {
            throw new InvalidArgumentException('Não foi possível receber o arquivo.');
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Arquivo grande demais para uma lista de totais.');
        }
        $contents = file_get_contents($upload['tmp_name']);
        if ($contents === false) {
            throw new InvalidArgumentException('Não foi possível ler o arquivo.');
        }
        return $contents;
    }
}
