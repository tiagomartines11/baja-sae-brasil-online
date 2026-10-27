<?php
declare(strict_types=1);

namespace Baja\Torneio;

/**
 * The "status" of a tournament prova's params, which juiz/torneio.php
 * manages:
 *
 *   frozen          true once "Congelar Classificação" fixed the seeding
 *   classification  the frozen list of car numbers, seed 1 first; false
 *                   while not frozen
 *
 * The help page asked organisers to start every config with it, and a config
 * without it crashed the page on "Congelar Classificação" (BAJAAPP-37). A
 * missing (or broken) status now reads as not frozen and is created when the
 * classification is frozen.
 */
final class Status
{
    /** $params->status, created (not frozen) when missing. */
    public static function de(object $params): object
    {
        if (!isset($params->status) || !is_object($params->status)) {
            $params->status = (object)['frozen' => false, 'classification' => false];
        }
        $params->status->frozen ??= false;
        $params->status->classification ??= false;
        return $params->status;
    }

    /** @param string $classificacao car numbers, seed 1 first, as the form posts them: "6,5,7,8" */
    public static function congelar(object $params, string $classificacao): void
    {
        $status = self::de($params);
        $status->frozen = true;
        $status->classification = array_map('intval', explode(',', $classificacao));
    }

    public static function descongelar(object $params): void
    {
        $status = self::de($params);
        $status->frozen = false;
        $status->classification = false;
    }
}
