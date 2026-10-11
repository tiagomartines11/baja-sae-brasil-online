<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Contagem\Engine\Settings;
use Baja\Model\Prova;

/**
 * "Predefinição de teste": the time settings of a prova, scaled down to a
 * race whose laps last a few seconds.
 *
 * A rehearsal with the simulation runs a four-hour race in a few minutes.
 * The real settings make no sense there: a 20-second matching window would
 * swallow two whole laps of a car that laps in ten seconds. The preset
 * derives every time setting from one number, the average lap of the
 * simulated race, keeping the same proportions the defaults have to a real
 * lap of about four minutes.
 *
 * The settings in force before the preset are kept in
 * `prova.params.contagem_salvo`, so one button puts them back.
 */
final class TestPreset
{
    /** The lap the default settings were chosen for, in seconds. */
    private const REAL_LAP_S = 240;

    /** Under this the matching window would be shorter than a person's typing. */
    public const SHORTEST_LAP_S = 5;
    public const LONGEST_LAP_S = 600;

    /**
     * The time settings for a race with this average lap. Everything that
     * is not a time (publish mode, classification field, ...) is left at
     * its default here; applyTo() keeps the prova's own values for those.
     */
    public static function forLap(int $voltaMediaS): Settings
    {
        $lapS = max(self::SHORTEST_LAP_S, min(self::LONGEST_LAP_S, $voltaMediaS));
        $defaults = new Settings();
        $scale = $lapS / self::REAL_LAP_S;

        $settings = new Settings();
        // Floors keep the settings usable for very short laps: a person
        // still needs a second or two to type a number, whatever the lap.
        $settings->janelaMs = max(1000, (int) round($defaults->janelaMs * $scale));
        $settings->duplicataMs = max(400, (int) round($defaults->duplicataMs * $scale));
        $settings->janelaRitmoS = max(4 * $lapS, (int) round($defaults->janelaRitmoS * $scale));
        $settings->dispersaoMaxMs = max(500, (int) round($defaults->dispersaoMaxMs * $scale));
        $settings->fonteOfflineS = max(10, (int) round($defaults->fonteOfflineS * $scale));
        $settings->dispositivoAusenteS = max(30, (int) round($defaults->dispositivoAusenteS * $scale));
        $settings->recalculoS = max(1, min($defaults->recalculoS, intdiv($lapS, 4)));
        return $settings;
    }

    /**
     * The prova's settings with the time settings replaced by the preset.
     * Simulation is switched on too: the preset exists for it.
     */
    public static function applyTo(Settings $current, int $voltaMediaS): Settings
    {
        $preset = self::forLap($voltaMediaS);
        $settings = clone $current;
        foreach (['janelaMs', 'duplicataMs', 'janelaRitmoS', 'dispersaoMaxMs', 'fonteOfflineS', 'dispositivoAusenteS', 'recalculoS'] as $name) {
            $settings->$name = $preset->$name;
        }
        $settings->permitirSimulacao = true;
        return $settings;
    }

    /** Keeps the settings in force now, unless a copy is already kept (a second preset must not overwrite the real ones). */
    public static function remember(Prova $prova): void
    {
        $params = $prova->getParams() ?? new \stdClass();
        if (isset($params->contagem_salvo)) {
            return;
        }
        $params->contagem_salvo = (object) Settings::fromArray($params->contagem ?? null)->toArray();
        $prova->setParams($params);
    }

    /** The settings kept by remember(), removed from the prova; null when there are none. */
    public static function takeRemembered(Prova $prova): ?Settings
    {
        $params = $prova->getParams() ?? new \stdClass();
        if (!isset($params->contagem_salvo)) {
            return null;
        }
        $saved = Settings::fromArray($params->contagem_salvo);
        unset($params->contagem_salvo);
        $prova->setParams($params);
        return $saved;
    }

    public static function hasRemembered(Prova $prova): bool
    {
        return isset(($prova->getParams() ?? new \stdClass())->contagem_salvo);
    }
}
