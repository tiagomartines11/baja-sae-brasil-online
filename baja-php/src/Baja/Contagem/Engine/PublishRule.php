<?php
declare(strict_types=1);

namespace Baja\Contagem\Engine;

use Baja\Contagem\Engine\Data\PassGroup;

/**
 * Step 4: which pass groups become official laps ("modo_publicacao").
 *
 * The mode changes only what counts. Flags are produced the same way in
 * every mode, so switching mode mid-race never hides a problem.
 */
final class PublishRule
{
    /**
     * @param array<int, PassGroup[]> $groupsByCar
     */
    public static function decide(array $groupsByCar, Settings $settings, SourceAvailability $availability): void
    {
        $mode = $settings->modoPublicacao;
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
                $group->contado = self::counts($group, $mode, $settings, $availability);
            }
        }
    }

    private static function counts(PassGroup $group, string $mode, Settings $settings, SourceAvailability $availability): bool
    {
        if ($mode === Settings::MODO_TODAS) {
            return true;
        }

        // A reviewer's word always counts, in every mode.
        if ($group->confirmadoPorRevisor || $group->adicionadoPorRevisor) {
            return true;
        }

        if ($mode === Settings::MODO_FONTE_PRINCIPAL) {
            return $group->hasSource((int) $settings->fontePrincipalId);
        }

        if (count($group->realSourceIds()) >= 2) {
            return true;
        }

        // A single source is enough only when it is the only one that was
        // supposed to be counting at that moment (the others are inactive or
        // were excluded by a reviewer). A source that is merely offline does
        // not qualify: its entry may still arrive, so the group waits.
        return count($availability->activeSourceIdsAt($group->primeiroTsMs)) <= 1;
    }
}
