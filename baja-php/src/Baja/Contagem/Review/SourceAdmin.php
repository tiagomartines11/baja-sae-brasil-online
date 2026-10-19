<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Model\ContagemDispositivo;
use Baja\Model\ContagemDispositivoQuery;
use Baja\Model\ContagemFonte;
use Baja\Model\ContagemFonteQuery;
use Baja\Model\ContagemPassagemQuery;
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
        Source::TIPO_SENSOR => 'Sensor de tempo (marca só o horário da passagem, nunca o número)',
        Source::TIPO_TOTAIS => 'Totais importados (papel)',
    ];

    /** The help line of the "sensor" type on the form. */
    public const SENSOR_HELP = 'Sensor de tempo: marca só o horário da passagem, nunca o número. Contagem: nunca conta. '
        . 'Horário: como votante, vale o do sensor em cada passagem em que uma marca dele coincide; nas outras, o dos contadores. '
        . 'Envia pela API, como uma fonte automática, e começa como observador (só medido).';

    public const TIMING_LABELS = [
        Source::TEMPO_HUMANA => 'Humana (tempos combinados pela mediana)',
        Source::TEMPO_PRECISA => 'Precisa (o tempo desta fonte vale como está)',
    ];

    public const ROLE_LABELS = [
        Source::PAPEL_VOTANTE => 'Votante (participa da contagem oficial)',
        Source::PAPEL_OBSERVADORA => 'Observadora (só medida: não muda contagem nem horário)',
    ];

    /** Form field: "I was told a promotion counts from now on, and said yes". */
    public const CAMPO_CONFIRMAR_VOTANTE = 'confirmar_votante';
    /** Form field: "I was told a precise source sets the pass times, and said yes". */
    public const CAMPO_CONFIRMAR_PRECISA = 'confirmar_precisa';
    /**
     * Form field of "ativa" being ticked again: '1' when the controller asks
     * for what the source sent while it was off to count after all.
     */
    public const CAMPO_CONTAR_PERIODO_DESATIVADA = 'contar_periodo_desativada';
    /** Form field of "Nova fonte": '1' creates the source switched off. */
    public const CAMPO_CRIAR_DESATIVADA = 'criar_desativada';

    /**
     * What a precise source does, said the same way wherever it is asked.
     * The first sentence answers "whose time counts?".
     */
    public const PRECISE_TIMING_WARNING = 'Horário: em cada passagem em que esta fonte estiver, vale o horário dela, não a mediana dos contadores; '
        . 'nas outras, vale a mediana. Esse horário vira também a referência para medir o atraso de cada contador. '
        . 'Use só para um sistema cujo relógio você conferiu.';

    /**
     * The same, for a timing sensor that takes part: it times passes, never
     * counts one. The first sentence answers "do the sensor's times replace
     * the counters'?" (PassTimeFuser::useSensorMarks).
     */
    public const SENSOR_TIMING_WARNING = 'Horário: em cada passagem em que uma marca do sensor coincide, vale o horário do sensor, não o dos contadores; '
        . 'nas passagens sem marca, vale o dos contadores. Contagem: continua só dos contadores; o sensor nunca conta uma passagem nem diz o carro. '
        . 'O horário do sensor passa a ser o da última passagem publicada, o do tempo das voltas e o da melhor volta. '
        . 'Um "Usar o tempo" da revisão, ou uma fonte de tempo preciso na mesma passagem, ainda vale antes dele. '
        . 'Use só um sensor cujo relógio você conferiu.';

    private const MAX_NAME_LENGTH = 45;
    private const MAX_URL_LENGTH = 255;
    private const MAX_LABEL_LENGTH = 100;

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

    /**
     * A new automatic source starts as an OBSERVER: a system nobody has
     * measured yet must not change the count by being plugged in. It is
     * promoted on this page once its comparison looks good. So does a timing
     * sensor: its marks then only show how far they are from the passes. The
     * form shows the role, observer chosen; picking votante there is asked
     * first, in the words of the promotion. A source can also be created
     * switched off, so nothing it sends counts until a controller switches
     * it on.
     *
     * @param array<string, mixed> $post
     * @throws ConfirmationNeeded when a source would count (or time passes) from the start and the person was not told what that does
     */
    public static function create(ContagemService $service, array $post): void
    {
        $prova = $service->getProva();
        $fonte = new ContagemFonte();
        $fonte->setEventoId($prova->getEventoId());
        $fonte->setProvaId($prova->getProvaId());
        $fonte->setAtiva(($post[self::CAMPO_CRIAR_DESATIVADA] ?? '') !== '1');
        // New sources go to the end of the list.
        $fonte->setOrdem(count(self::sources($prova)) + 1);
        self::applyEditableFields($fonte, $post);
        $role = self::postedRole($fonte, $post, Source::PAPEL_OBSERVADORA);
        if ($role === Source::PAPEL_VOTANTE && self::mayObserve($fonte) && ($post[self::CAMPO_CONFIRMAR_VOTANTE] ?? '') !== '1') {
            throw self::votanteQuestion($fonte, sprintf('Criar "%s" como votante, desde já.', $fonte->getNome()), 'Criar como votante');
        }
        $fonte->setPapel($role);
        self::askAboutPreciseTiming($fonte, false, $post);
        $fonte->save();
        self::rerunEngine($service);
    }

    /**
     * @param array<string, mixed> $post
     * @throws ConfirmationNeeded for a promotion to votante, or a source starting to count with precise timing, not confirmed yet
     */
    public static function update(ContagemService $service, array $post): void
    {
        $fonte = self::requireSource($service->getProva(), $post['fonte_id'] ?? null);
        $wasActive = (bool) $fonte->getAtiva();
        $previousType = (string) $fonte->getTipo();
        $previousRole = (string) $fonte->getPapel();
        $wasCountingPrecisely = $previousRole === Source::PAPEL_VOTANTE && $fonte->getQualidadeTempo() === Source::TEMPO_PRECISA;
        self::applyEditableFields($fonte, $post);

        $order = filter_var($post['ordem'] ?? null, FILTER_VALIDATE_INT);
        if ($order === false || $order < 0 || $order > 999) {
            throw new InvalidArgumentException('Ordem inválida: use um número entre 0 e 999.');
        }
        $fonte->setOrdem($order);

        $staysActive = ($post['ativa'] ?? '') === '1';
        self::refuseReinterpretingSensorMarks($fonte, $previousType);
        // A source that only now becomes an automatic one or a sensor, with
        // nothing recorded yet, is a new one of that kind: it starts as an
        // observer, as if it had been created so (its form had no role).
        $becomesObservable = !isset($post['papel']) && self::mayObserve($fonte) && !in_array($previousType, [Source::TIPO_AUTOMATICA, Source::TIPO_SENSOR], true)
            && !ContagemPassagemQuery::create()->filterByFonteId($fonte->getFonteId())->exists();
        if ($becomesObservable) {
            $previousRole = Source::PAPEL_OBSERVADORA;
            $fonte->setPapel(Source::PAPEL_OBSERVADORA);
            $fonte->setPapelDesdeMs(null);
        }
        $role = self::postedRole($fonte, $post, $previousRole);
        self::refuseBreakingThePrimarySource($service->getSettings(), $fonte, $staysActive, $previousType, $role);

        $nowMs = Clock::nowMs();
        $periods = Source::periodsFromJson($fonte->getPeriodosFora());
        if ($previousRole === Source::PAPEL_OBSERVADORA && $role === Source::PAPEL_VOTANTE) {
            if (($post[self::CAMPO_CONFIRMAR_VOTANTE] ?? '') !== '1') {
                throw self::votanteQuestion(
                    $fonte,
                    $fonte->getTipo() === Source::TIPO_SENSOR
                        ? sprintf('Promover "%s" a votante vale a partir de agora: o que ele marcou como observador continua só comparado, não vale para trás.', $fonte->getNome())
                        : sprintf('Promover "%s" a votante vale a partir de agora: o que ela registrou como observadora continua fora da contagem, não vale para trás.', $fonte->getNome()),
                    'Promover a votante'
                );
            }
            // Not backwards: the time it observed becomes a period off,
            // marked as observed so it is still compared with the count.
            $periods[] = [$fonte->getPapelDesdeMs() === null ? null : (int) $fonte->getPapelDesdeMs(), $nowMs, Source::PERIODO_OBSERVADORA];
            $fonte->setPapelDesdeMs($nowMs);
        } elseif ($previousRole === Source::PAPEL_VOTANTE && $role === Source::PAPEL_OBSERVADORA) {
            // Like switching a counter off: the votes it cast stay.
            $fonte->setPapelDesdeMs($nowMs);
        }
        $fonte->setPapel($role);
        if ($role === Source::PAPEL_VOTANTE) {
            self::askAboutPreciseTiming($fonte, $wasCountingPrecisely, $post);
        }

        if ($wasActive && !$staysActive) {
            // Switching a counter off must not erase what it recorded. The
            // moment is kept, and the engine goes on using the source's
            // entries from before it (Source::wasCountingAt).
            $fonte->setDesativadaEmMs($nowMs);
        } elseif (!$wasActive && $staysActive) {
            // Its phone may have gone on sending while it was off. Unless the
            // controller asks for those entries, they stay out: switching a
            // source on again must not bring them back silently.
            if (($post[self::CAMPO_CONTAR_PERIODO_DESATIVADA] ?? '') !== '1') {
                $periods[] = [$fonte->getDesativadaEmMs() === null ? null : (int) $fonte->getDesativadaEmMs(), $nowMs];
            }
            $fonte->setDesativadaEmMs(null);
        }
        $fonte->setPeriodosFora(Source::periodsToJson($periods));
        $fonte->setAtiva($staysActive);
        $fonte->save();
        self::rerunEngine($service);
    }

    /**
     * How each observer source compares with the official count, from the
     * latest snapshot (ObserverComparator). Nothing is asked, and nothing is
     * recalculated, while no source is or was an observer. A promoted source
     * keeps the comparison of the time it observed.
     *
     * @return array<int, array<string, mixed>> fonteId => the 'observacao' part of its statistics
     */
    public static function observerComparisons(ContagemService $service): array
    {
        $hasObserver = false;
        foreach (self::sources($service->getProva()) as $fonte) {
            $hasObserver = $hasObserver || self::hasObserved($fonte);
        }
        if (!$hasObserver) {
            return [];
        }
        $comparisons = [];
        foreach ($service->snapshot()->dados['estado']['fontes'] ?? [] as $row) {
            if (isset($row['stats']['observacao'])) {
                $comparisons[(int) $row['id']] = $row['stats']['observacao'];
            }
        }
        return $comparisons;
    }

    /**
     * How each timing sensor that takes part is doing (SourceStats::$sensor),
     * from the latest snapshot. Nothing is asked while the prova has no
     * sensor.
     *
     * @return array<int, array<string, mixed>> fonteId => the 'sensor' part of its statistics
     */
    public static function sensorStats(ContagemService $service): array
    {
        $hasSensor = false;
        foreach (self::sources($service->getProva()) as $fonte) {
            $hasSensor = $hasSensor || $fonte->getTipo() === Source::TIPO_SENSOR;
        }
        if (!$hasSensor) {
            return [];
        }
        $stats = [];
        foreach ($service->snapshot()->dados['estado']['fontes'] ?? [] as $row) {
            if (isset($row['stats']['sensor'])) {
                $stats[(int) $row['id']] = $row['stats']['sensor'];
            }
        }
        return $stats;
    }

    /**
     * True for a pass source that is an observer, or was one before being
     * promoted (Source::hasObserved): it has a comparison to show.
     */
    public static function hasObserved(ContagemFonte $fonte): bool
    {
        if ($fonte->getTipo() === Source::TIPO_TOTAIS || $fonte->getTipo() === Source::TIPO_BOX) {
            return false;
        }
        if ($fonte->getPapel() === Source::PAPEL_OBSERVADORA) {
            return true;
        }
        foreach (Source::periodsFromJson($fonte->getPeriodosFora()) as $period) {
            if (($period[2] ?? null) === Source::PERIODO_OBSERVADORA) {
                return true;
            }
        }
        return false;
    }

    /**
     * The image base URL of each automatic source that has one.
     *
     * @return array<int, string> fonteId => base URL
     */
    public static function imageBaseUrls(Prova $prova): array
    {
        $urls = [];
        foreach (self::sources($prova) as $fonte) {
            $url = $fonte->getImagemBaseUrl();
            if ($fonte->getTipo() === Source::TIPO_AUTOMATICA && $url !== null && $url !== '') {
                $urls[(int) $fonte->getFonteId()] = (string) $url;
            }
        }
        return $urls;
    }

    /** Only an automatic source and a timing sensor can be observers. */
    public static function mayObserve(ContagemFonte $fonte): bool
    {
        return $fonte->getTipo() === Source::TIPO_AUTOMATICA || $fonte->getTipo() === Source::TIPO_SENSOR;
    }

    /**
     * The question asked before an automatic source or a timing sensor takes
     * part, created so or promoted: what it will do first, then `$when`.
     * With precise timing the question says what that does too, and its
     * "yes" answers both (one question, not two in a row).
     */
    private static function votanteQuestion(ContagemFonte $fonte, string $when, string $buttonLabel): ConfirmationNeeded
    {
        $precise = $fonte->getQualidadeTempo() === Source::TEMPO_PRECISA;
        if ($fonte->getTipo() === Source::TIPO_SENSOR) {
            $message = self::SENSOR_TIMING_WARNING . ' ' . $when;
        } else {
            $message = 'Contagem: o que esta fonte registrar conta na contagem oficial, como um contador.'
                . ($precise ? ' ' . self::PRECISE_TIMING_WARNING : '') . ' ' . $when;
        }
        $question = new ConfirmationNeeded($message, self::CAMPO_CONFIRMAR_VOTANTE, $buttonLabel);
        if ($precise) {
            $question->campos[self::CAMPO_CONFIRMAR_PRECISA] = '1';
        }
        return $question;
    }

    /**
     * The role chosen on the form. Only an automatic source or a timing
     * sensor can be an observer; another kind of source is always a votante
     * (turning an observer into a manual source is a promotion, and asked
     * as one).
     *
     * @param array<string, mixed> $post
     */
    private static function postedRole(ContagemFonte $fonte, array $post, string $previousRole): string
    {
        if (!self::mayObserve($fonte)) {
            return Source::PAPEL_VOTANTE;
        }
        $role = $post['papel'] ?? $previousRole;
        if (!is_string($role) || !isset(self::ROLE_LABELS[$role])) {
            throw new InvalidArgumentException('Papel de fonte inválido.');
        }
        return $role;
    }

    /**
     * A precise source sets the pass time of every pass it is in. Starting
     * to count with one is asked first; an observer, which counts nothing,
     * is not asked.
     *
     * @param array<string, mixed> $post
     */
    private static function askAboutPreciseTiming(ContagemFonte $fonte, bool $wasCountingPrecisely, array $post): void
    {
        $countsPrecisely = $fonte->getPapel() === Source::PAPEL_VOTANTE && $fonte->getQualidadeTempo() === Source::TEMPO_PRECISA;
        if ($countsPrecisely && !$wasCountingPrecisely && ($post[self::CAMPO_CONFIRMAR_PRECISA] ?? '') !== '1') {
            $warning = $fonte->getTipo() === Source::TIPO_SENSOR ? self::SENSOR_TIMING_WARNING : self::PRECISE_TIMING_WARNING;
            throw new ConfirmationNeeded($warning, self::CAMPO_CONFIRMAR_PRECISA, 'Usar tempo preciso');
        }
    }

    /**
     * In the "fonte principal" mode the official count IS what the primary
     * source recorded. Switching that source off, or turning it into a list
     * of paper totals, would silently take every lap away from every car.
     */
    private static function refuseBreakingThePrimarySource(
        Settings $settings,
        ContagemFonte $fonte,
        bool $staysActive,
        string $previousType,
        string $role = Source::PAPEL_VOTANTE
    ): void {
        $isPrimaryInUse = $settings->modoPublicacao === Settings::MODO_FONTE_PRINCIPAL
            && $settings->fontePrincipalId === (int) $fonte->getFonteId();
        if (!$isPrimaryInUse) {
            return;
        }
        if (!$staysActive) {
            throw new InvalidArgumentException(
                'Esta é a fonte principal e o modo de publicação é "fonte principal": não é possível desativá-la. '
                . 'Troque a fonte principal ou o modo de publicação nas configurações primeiro.'
            );
        }
        if ($fonte->getTipo() !== $previousType) {
            throw new InvalidArgumentException(
                'Esta é a fonte principal e o modo de publicação é "fonte principal": não é possível mudar o tipo dela. '
                . 'Troque a fonte principal ou o modo de publicação nas configurações primeiro.'
            );
        }
        if ($role === Source::PAPEL_OBSERVADORA) {
            throw new InvalidArgumentException(
                'Esta é a fonte principal e o modo de publicação é "fonte principal": ela não pode virar observadora, '
                . 'porque nada do que uma observadora registra conta. Troque a fonte principal ou o modo de publicação nas configurações primeiro.'
            );
        }
    }

    /**
     * A timing sensor's marks and a counter's entries mean different things
     * with the same rows: turned into a sensor, a counter's numbers would be
     * ignored; turned into a counter, every mark of a sensor would become a
     * "??" to review. Once the source has recorded anything, its type stays
     * what it was on that side.
     */
    private static function refuseReinterpretingSensorMarks(ContagemFonte $fonte, string $previousType): void
    {
        $type = (string) $fonte->getTipo();
        if ($type === $previousType || ($type !== Source::TIPO_SENSOR && $previousType !== Source::TIPO_SENSOR)) {
            return;
        }
        if (ContagemPassagemQuery::create()->filterByFonteId($fonte->getFonteId())->exists()) {
            throw new InvalidArgumentException(
                sprintf('"%s" já tem registros: não é possível transformá-la em sensor de tempo, nem um sensor em outro tipo. ', $fonte->getNome())
                . 'Crie uma fonte nova com o tipo certo.'
            );
        }
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
        if ($fonte->getTipo() !== Source::TIPO_AUTOMATICA && $fonte->getTipo() !== Source::TIPO_SENSOR) {
            throw new InvalidArgumentException('Tokens de API só existem para fontes automáticas e sensores de tempo.');
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
            $text = TotalsImport::readUpload($upload);
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

    /**
     * @param array<string, mixed> $post
     * @throws ConfirmationNeeded see SourceSettings::save()
     */
    public static function saveSettings(ContagemService $service, array $post): void
    {
        SourceSettings::save($service, $post);
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
        // A timing sensor is there for its time: it is always precise.
        $timing = $type === Source::TIPO_SENSOR ? Source::TEMPO_PRECISA : ($post['qualidade_tempo'] ?? '');
        if (!is_string($timing) || !isset(self::TIMING_LABELS[$timing])) {
            throw new InvalidArgumentException('Qualidade de tempo inválida.');
        }
        $fonte->setNome($name);
        $fonte->setTipo($type);
        $fonte->setQualidadeTempo($timing);
        // Only a form that shows the field sends it; the others keep what is stored.
        if (array_key_exists('imagem_base_url', $post)) {
            $fonte->setImagemBaseUrl($type === Source::TIPO_AUTOMATICA ? self::imageBaseUrl($post['imagem_base_url']) : null);
        }
    }

    /**
     * The prefix of the image links of an automatic source: empty for none,
     * otherwise an http(s) address. An http one is allowed on purpose: the
     * pictures usually live on a box beside the track, on the local network
     * (see "Visão computacional" in docs/contagem.md for what that costs).
     *
     * It must have a path after the host ("http://box/" at least): the
     * picture's id is appended to it, and with no "/" an id sent by the
     * system ("-x.example.org") would become part of the host name.
     *
     * @param mixed $value
     */
    private static function imageBaseUrl($value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }
        if (strlen($url) > self::MAX_URL_LENGTH || preg_match('#^https?://[^/\s"\'<>`]+/[^\s"\'<>`]*$#i', $url) !== 1) {
            throw new InvalidArgumentException(
                'Endereço das imagens inválido: use um endereço http:// ou https:// com o caminho das imagens '
                . '(por exemplo http://camera.local/imagens/), de até ' . self::MAX_URL_LENGTH . ' caracteres, ou deixe vazio.'
            );
        }
        return $url;
    }

    /** @param mixed $value */
    private static function cleanText($value, int $maxLength): string
    {
        $text = is_string($value) ? trim(preg_replace('/\s+/u', ' ', $value) ?? '') : '';
        return mb_substr($text, 0, $maxLength);
    }
}
