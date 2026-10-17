<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\EngineInput;
use Baja\Contagem\Engine\Data\EngineResult;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\RaceTimeline;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\RaceContext;
use Baja\Contagem\Service\SourceLoader;
use Baja\Model\Prova;

/**
 * The sources and their devices as the review screen shows them: the
 * statistics of each counter, and for each phone whether it is still in
 * contact, went quiet, was closed by its counter or was revoked.
 *
 * A reviewer needs the device list to answer "why is the system waiting?":
 * a pass seen by one counter stays pending while another counter's phone is
 * expected to upload. This shows which phone that is.
 */
final class SourceOverview
{
    /** In contact within `fonte_offline_s`. */
    public const DEVICE_ATIVO = 'ativo';
    /** Quiet for longer than `fonte_offline_s`, but its upload is still waited for. */
    public const DEVICE_SEM_CONTATO = 'sem_contato';
    /** Quiet for longer than `dispositivo_ausente_s`: no longer waited for. */
    public const DEVICE_SILENCIOSO = 'silencioso';
    /** Its counter pressed "Encerrar meu turno". */
    public const DEVICE_FECHADO = 'fechado';
    /** A controller revoked it. */
    public const DEVICE_REVOGADO = 'revogado';
    /** Registered but never uploaded anything, not even an empty heartbeat. */
    public const DEVICE_NUNCA = 'nunca';

    /**
     * @return array<int, array<string, mixed>> counting and totals sources, in display order
     */
    public static function describeSources(EngineInput $input, EngineResult $result): array
    {
        $sources = array_values(array_filter(
            $input->sources,
            // The pit log's internal source is plumbing, not something to review.
            fn(Source $source) => $source->tipo !== Source::TIPO_BOX
        ));
        usort($sources, fn(Source $a, Source $b) => [$a->ordem, $a->id] <=> [$b->ordem, $b->id]);

        $primarySourceId = $input->settings->fontePrincipalId;
        $rows = [];
        foreach ($sources as $source) {
            $stats = isset($result->sourceStats[$source->id]) ? $result->sourceStats[$source->id]->toArray() : null;
            if ($stats !== null) {
                // "No device is open on this source" is stored as the largest
                // integer there is. That is not a moment, and JavaScript
                // could not even hold the number, so it is sent as a flag.
                $stats['nada_a_esperar'] = $stats['sincronizado_ate_ms'] === Source::NOTHING_TO_WAIT_FOR;
                if ($stats['nada_a_esperar']) {
                    $stats['sincronizado_ate_ms'] = null;
                }
            }
            $rows[] = [
                'id' => $source->id,
                'nome' => $source->nome,
                'tipo' => $source->tipo,
                'qualidade_tempo' => $source->qualidadeTempo,
                'ativa' => $source->ativa,
                'desativada_em_ms' => $source->desativadaEmMs,
                // 'votante' | 'observadora' (compared with the count, never
                // counted; its stats carry 'observacao').
                'papel' => $source->papel,
                'papel_desde_ms' => $source->papelDesdeMs,
                'principal' => $primarySourceId !== null && $primarySourceId === $source->id,
                'stats' => $stats,
            ];
        }
        return $rows;
    }

    /**
     * Every device of the prova with its state at $nowMs.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function describeDevices(Prova $prova, Settings $settings, int $nowMs): array
    {
        $loader = new SourceLoader((string) $prova->getEventoId(), (string) $prova->getProvaId());

        $rows = [];
        foreach ($loader->loadDevices() as $device) {
            $rows[] = [
                'id' => $device['dispositivo_id'],
                'fonte_id' => $device['fonte_id'],
                'usuario' => $device['usuario'],
                'rotulo' => $device['rotulo'],
                'visto_em_ms' => $device['visto_em_ms'],
                'sincronizado_ate_ms' => $device['sincronizado_ate_ms'],
                'revogado_em_ms' => $device['revogado_em_ms'],
                'fechado_em_ms' => $device['fechado_em_ms'],
                // "Open" is the engine's own word for "its upload is still waited for".
                'aberto' => SourceLoader::isOpen($device, $settings, $nowMs),
                'situacao' => self::deviceState($device, $settings, $nowMs),
            ];
        }
        return $rows;
    }

    /**
     * When each source was last heard from and whether it counts as offline
     * now. Mirrors the rule of the engine's source statistics (a counting
     * source is offline when the race is on and none of its devices has been
     * heard from for `fonte_offline_s`), applied to fresh contact times.
     *
     * @param array<int, array<string, mixed>> $sourceRows as made by describeSources()
     * @param array<int, array<string, mixed>> $devices as made by describeDevices()
     * @param array<string, mixed> $liveNumbers the 'tempo_real' part of a snapshot
     *
     * It also says how far each source has synced, by the engine's rule
     * (SourceLoader): the earliest mark among its open devices, unknown
     * while an open device has never reported an empty queue, and "nothing
     * to wait for" when no device is open. The snapshot's token does not
     * change when only these numbers do (StateSnapshot::LIVE_KEYS), so the
     * screen gets them from here with every answer.
     *
     * @return array<int, array{visto_em_ms: ?int, offline: bool, sincronizado_ate_ms: ?int, nada_a_esperar: bool}> fonteId => contact
     */
    public static function contactBySource(array $sourceRows, array $devices, Settings $settings, array $liveNumbers, int $nowMs): array
    {
        $lastSeenBySource = [];
        $openDevicesBySource = [];
        // A source with an open device that never reported an empty queue.
        $unknownMarkBySource = [];
        $earliestMarkBySource = [];
        foreach ($devices as $device) {
            $seenMs = $device['visto_em_ms'];
            $fonteId = (int) $device['fonte_id'];
            if ($seenMs !== null && $seenMs > ($lastSeenBySource[$fonteId] ?? 0)) {
                $lastSeenBySource[$fonteId] = (int) $seenMs;
            }
            if (empty($device['aberto'])) {
                continue;
            }
            $openDevicesBySource[$fonteId] = true;
            if ($device['sincronizado_ate_ms'] === null) {
                $unknownMarkBySource[$fonteId] = true;
            } else {
                $earliestMarkBySource[$fonteId] = min($earliestMarkBySource[$fonteId] ?? PHP_INT_MAX, (int) $device['sincronizado_ate_ms']);
            }
        }

        $raceState = RaceContext::timelineOf($liveNumbers['corrida'] ?? [])->stateAt($nowMs);
        $raceIsOn = $raceState === RaceTimeline::CORRENDO || $raceState === RaceTimeline::BANDEIRA_VERMELHA;

        $contact = [];
        foreach ($sourceRows as $source) {
            $fonteId = (int) $source['id'];
            $lastSeenMs = $lastSeenBySource[$fonteId] ?? null;
            $countsPasses = !empty($source['ativa'])
                && in_array($source['tipo'] ?? '', [Source::TIPO_MANUAL, Source::TIPO_AUTOMATICA], true);
            $silent = $lastSeenMs === null || $nowMs - $lastSeenMs > $settings->fonteOfflineS * 1000;
            $hasOpenDevice = isset($openDevicesBySource[$fonteId]);
            $contact[$fonteId] = [
                'visto_em_ms' => $lastSeenMs,
                'offline' => $countsPasses && $raceIsOn && $silent,
                'sincronizado_ate_ms' => $hasOpenDevice && !isset($unknownMarkBySource[$fonteId]) ? $earliestMarkBySource[$fonteId] : null,
                'nada_a_esperar' => !$hasOpenDevice,
            ];
        }
        return $contact;
    }

    /**
     * @param array{revogado_em_ms: ?int, fechado_em_ms: ?int, visto_em_ms: ?int} $device
     */
    private static function deviceState(array $device, Settings $settings, int $nowMs): string
    {
        if ($device['revogado_em_ms'] !== null) {
            return self::DEVICE_REVOGADO;
        }
        if ($device['visto_em_ms'] === null) {
            return self::DEVICE_NUNCA;
        }
        if ($device['fechado_em_ms'] !== null && $device['visto_em_ms'] <= $device['fechado_em_ms']) {
            return self::DEVICE_FECHADO;
        }
        $silentForMs = $nowMs - $device['visto_em_ms'];
        if ($silentForMs > $settings->dispositivoAusenteS * 1000) {
            return self::DEVICE_SILENCIOSO;
        }
        if ($silentForMs > $settings->fonteOfflineS * 1000) {
            return self::DEVICE_SEM_CONTATO;
        }
        return self::DEVICE_ATIVO;
    }
}
