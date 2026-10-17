<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Propel\Runtime\Propel;

/**
 * Loads the sources of a prova and works out, from their devices, how far
 * each source is known to have uploaded.
 *
 * A source can have several devices over a race: a relief counter's phone
 * during a handover, a replacement for a dead battery. The rule:
 *
 *   A source is synced through the EARLIEST mark among its OPEN devices.
 *
 * Earliest, because one phone's empty queue says nothing about entries
 * another phone is still holding. And only open devices, because a device
 * that will not come back must not hold the whole race hostage. A device is
 * open from its first contact until one of three things happens:
 *
 *   - a controller revokes it;
 *   - its counter presses "Encerrar meu turno" (it reports itself closed);
 *   - it has been silent for longer than `dispositivo_ausente_s`.
 *
 * A device that is no longer open has, by definition, nothing more to add
 * that we are waiting for; what it uploaded before stays valid. If it comes
 * back, it is open again.
 *
 * A source with no open device at all has nobody whose upload could still
 * arrive, so there is nothing to wait for.
 */
final class SourceLoader
{
    private string $eventoId;
    private string $provaId;

    public function __construct(string $eventoId, string $provaId)
    {
        $this->eventoId = $eventoId;
        $this->provaId = $provaId;
    }

    /**
     * @return Source[]
     */
    public function load(Settings $settings, int $nowMs): array
    {
        $devicesBySource = [];
        foreach ($this->loadDevices() as $device) {
            $devicesBySource[$device['fonte_id']][] = $device;
        }

        $sql = 'SELECT fonte_id, nome, tipo, qualidade_tempo, ativa, ordem, desativada_em_ms,
                       papel, papel_desde_ms, periodos_fora
                  FROM contagem_fonte
                 WHERE evento_id = :evento AND prova_id = :prova
              ORDER BY ordem, fonte_id';
        $statement = Propel::getReadConnection('resultados')->prepare($sql);
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);

        $sources = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $devices = $devicesBySource[(int) $row['fonte_id']] ?? [];
            $source = new Source(
                (int) $row['fonte_id'],
                (string) $row['nome'],
                (string) $row['tipo'],
                (string) $row['qualidade_tempo'],
                (bool) $row['ativa'],
                (int) $row['ordem'],
                self::syncedThrough($devices, $settings, $nowMs),
                self::lastSeen($devices)
            );
            $source->desativadaEmMs = $row['desativada_em_ms'] === null ? null : (int) $row['desativada_em_ms'];
            $source->papel = $row['papel'] === Source::PAPEL_OBSERVADORA ? Source::PAPEL_OBSERVADORA : Source::PAPEL_VOTANTE;
            $source->papelDesdeMs = self::nullableInt($row['papel_desde_ms']);
            $source->periodosForaMs = Source::periodsFromJson($row['periodos_fora'] === null ? null : (string) $row['periodos_fora']);
            $sources[] = $source;
        }
        return $sources;
    }

    /**
     * Every device of the prova, with whether it is open right now.
     *
     * @return array<int, array{dispositivo_id: int, fonte_id: int, usuario: ?string, rotulo: ?string, revogado_em_ms: ?int, fechado_em_ms: ?int, visto_em_ms: ?int, sincronizado_ate_ms: ?int}>
     */
    public function loadDevices(): array
    {
        $sql = 'SELECT d.dispositivo_id, d.fonte_id, d.usuario, d.rotulo, d.revogado_em_ms, d.fechado_em_ms,
                       d.visto_em_ms, d.sincronizado_ate_ms
                  FROM contagem_dispositivo d
                  JOIN contagem_fonte f ON f.fonte_id = d.fonte_id
                 WHERE f.evento_id = :evento AND f.prova_id = :prova
              ORDER BY d.dispositivo_id';
        $statement = Propel::getReadConnection('resultados')->prepare($sql);
        $statement->execute([':evento' => $this->eventoId, ':prova' => $this->provaId]);

        $devices = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $devices[] = [
                'dispositivo_id' => (int) $row['dispositivo_id'],
                'fonte_id' => (int) $row['fonte_id'],
                'usuario' => $row['usuario'] === null ? null : (string) $row['usuario'],
                'rotulo' => $row['rotulo'] === null ? null : (string) $row['rotulo'],
                'revogado_em_ms' => self::nullableInt($row['revogado_em_ms']),
                'fechado_em_ms' => self::nullableInt($row['fechado_em_ms']),
                'visto_em_ms' => self::nullableInt($row['visto_em_ms']),
                'sincronizado_ate_ms' => self::nullableInt($row['sincronizado_ate_ms']),
            ];
        }
        return $devices;
    }

    /**
     * Is somebody still expected to upload from this device?
     *
     * @param array{revogado_em_ms: ?int, fechado_em_ms: ?int, visto_em_ms: ?int} $device
     */
    public static function isOpen(array $device, Settings $settings, int $nowMs): bool
    {
        if ($device['revogado_em_ms'] !== null || $device['visto_em_ms'] === null) {
            return false;
        }
        // Closed by its counter, and not heard from since.
        if ($device['fechado_em_ms'] !== null && $device['visto_em_ms'] <= $device['fechado_em_ms']) {
            return false;
        }
        return $nowMs - $device['visto_em_ms'] <= $settings->dispositivoAusenteS * 1000;
    }

    /**
     * @param array<int, array{revogado_em_ms: ?int, fechado_em_ms: ?int, visto_em_ms: ?int, sincronizado_ate_ms: ?int}> $devices
     */
    private static function syncedThrough(array $devices, Settings $settings, int $nowMs): ?int
    {
        $earliestMarkMs = Source::NOTHING_TO_WAIT_FOR;
        foreach ($devices as $device) {
            if (!self::isOpen($device, $settings, $nowMs)) {
                continue;
            }
            if ($device['sincronizado_ate_ms'] === null) {
                // An open device that has never reported an empty queue:
                // nothing is known about this source yet.
                return null;
            }
            $earliestMarkMs = min($earliestMarkMs, $device['sincronizado_ate_ms']);
        }
        return $earliestMarkMs;
    }

    /**
     * @param array<int, array{visto_em_ms: ?int}> $devices
     */
    private static function lastSeen(array $devices): ?int
    {
        $lastSeenMs = null;
        foreach ($devices as $device) {
            if ($device['visto_em_ms'] !== null && ($lastSeenMs === null || $device['visto_em_ms'] > $lastSeenMs)) {
                $lastSeenMs = $device['visto_em_ms'];
            }
        }
        return $lastSeenMs;
    }

    /** @param mixed $value */
    private static function nullableInt($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
