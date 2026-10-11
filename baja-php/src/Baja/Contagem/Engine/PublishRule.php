<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Which pass groups are official passes ("modo_publicacao").
 *
 * The mode changes only what counts. For every group this class also works
 * out `referencia`: whether it would count under the default rule. Flags
 * and the pace model use that, so they are the same in every mode and
 * switching mode mid-race never hides a problem.
 *
 * Nothing here looks at whether a source has synced. A count that depended
 * on that would change by itself as phones go in and out of coverage.
 */
final class PublishRule
{
    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @return string the counting rule actually applied (one of Settings::MODO_*), after the fallbacks below
     */
    public static function decide(array $groupsByCar, Settings $settings, SourceAvailability $availability): string
    {
        $mode = $settings->modoPublicacao;

        // "desligada" switches off publishing to the scoring system, not
        // counting: the review screen and the feed still need a count.
        if ($mode === Settings::MODO_DESLIGADA) {
            $mode = Settings::MODO_CONFIRMADAS;
        }

        $primaryIsCounting = $settings->fontePrincipalId !== null
            && $availability->isCountingSource($settings->fontePrincipalId);
        if ($mode === Settings::MODO_FONTE_PRINCIPAL && !$primaryIsCounting) {
            // "Trust the primary source" with no primary chosen, or with one
            // that was since deactivated, would count nothing at all. Falling
            // back to the default is the safe reading.
            $mode = Settings::MODO_CONFIRMADAS;
        }

        foreach ($groupsByCar as $groups) {
            foreach ($groups as $group) {
                $group->referencia = self::countsByAgreement($group, $availability);
                $group->contado = self::counts($group, $mode, $settings);
            }
        }
        return $mode;
    }

    private static function counts(PassGroup $group, string $mode, Settings $settings): bool
    {
        if ($mode === Settings::MODO_TODAS) {
            return true;
        }
        if ($mode === Settings::MODO_FONTE_PRINCIPAL) {
            // A reviewer's word always counts, in every mode.
            return $group->confirmadoPorRevisor
                || $group->adicionadoPorRevisor
                || $group->hasSource((int) $settings->fontePrincipalId);
        }
        return $group->referencia;
    }

    /** The default rule ("confirmadas"). */
    private static function countsByAgreement(PassGroup $group, SourceAvailability $availability): bool
    {
        if ($group->confirmadoPorRevisor || $group->adicionadoPorRevisor) {
            return true;
        }
        if (count($group->realSourceIds()) >= 2) {
            return true;
        }

        // A single source is enough only when it is the only one that was
        // supposed to be counting at that moment (the others are switched
        // off or were excluded by a reviewer). A source that is merely
        // offline does not qualify: its entry may still arrive, so the
        // group waits.
        return count($availability->activeSourceIdsAt($group->primeiroTsMs)) <= 1;
    }
}
