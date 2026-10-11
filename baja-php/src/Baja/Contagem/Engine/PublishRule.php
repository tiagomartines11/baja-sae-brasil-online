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
 *
 * Two things hold in every mode:
 * - a reviewer's word counts (a confirmed group, an added pass);
 * - a car that is present but not classified gets no official pass on the
 *   counters' word alone, however many of them agree.
 */
final class PublishRule
{
    /**
     * @param array<int, PassGroup[]> $groupsByCar
     * @param array<int, true> $unclassifiedCars car numbers present at the event but not classified
     * @return string the counting rule actually applied (one of Settings::MODO_*), after the fallbacks below
     */
    public static function decide(array $groupsByCar, Settings $settings, SourceAvailability $availability, array $unclassifiedCars = []): string
    {
        $mode = $settings->modoPublicacao;

        // "desligada" switches off publishing to the scoring system, not
        // counting: the review screen and the feed still need a count.
        if ($mode === Settings::MODO_DESLIGADA) {
            $mode = Settings::MODO_CONFIRMADAS;
        }

        $primaryId = $settings->fontePrincipalId;
        if ($mode === Settings::MODO_FONTE_PRINCIPAL && $primaryId === null) {
            // "Trust the primary source" with no primary chosen would count
            // nothing at all. Falling back to the default is the safe reading.
            // (A primary that is not a counter at all was never "supposed to
            // be counting", so the rule below gives the same result for it.)
            $mode = Settings::MODO_CONFIRMADAS;
        }
        // The primary can only be trusted for the moments at which it was
        // supposed to be counting. Usually that is the whole race, which is
        // worth knowing once instead of asking for every group.
        $primaryCountedAllAlong = $mode === Settings::MODO_FONTE_PRINCIPAL && $availability->wasAlwaysSupposedToCount((int) $primaryId);

        foreach ($groupsByCar as $carro => $groups) {
            $isUnclassified = isset($unclassifiedCars[$carro]);
            foreach ($groups as $group) {
                if ($isUnclassified) {
                    $group->referencia = $group->confirmadoPorRevisor || $group->adicionadoPorRevisor;
                    $group->contado = $group->referencia;
                    continue;
                }
                $group->referencia = self::countsByAgreement($group, $availability);
                if ($mode === Settings::MODO_CONFIRMADAS) {
                    $group->contado = $group->referencia;
                } elseif ($mode === Settings::MODO_TODAS) {
                    $group->contado = true;
                } elseif ($primaryCountedAllAlong || $availability->wasSupposedToCountAt((int) $primaryId, $group->primeiroTsMs)) {
                    $group->contado = $group->confirmadoPorRevisor
                        || $group->adicionadoPorRevisor
                        || $group->hasSource((int) $primaryId);
                } else {
                    // The primary was excluded or switched off at this
                    // moment: it has nothing here, by decision and not by
                    // mistake. Counting "what the primary has" would zero
                    // this stretch of the race, so the other sources decide
                    // it by agreement.
                    $group->contado = $group->referencia;
                }
            }
        }
        return $mode;
    }

    /** The default rule ("confirmadas"). */
    private static function countsByAgreement(PassGroup $group, SourceAvailability $availability): bool
    {
        if ($group->confirmadoPorRevisor || $group->adicionadoPorRevisor) {
            return true;
        }
        // Two entries are two sources: a group holds at most one entry per
        // source, and the reviewer's own (an added pass) was dealt with above.
        if (count($group->entries) >= 2) {
            return true;
        }

        // A single source is enough only when it is the only one that was
        // supposed to be counting at that moment (the others are switched
        // off or were excluded by a reviewer). A source that is merely
        // offline does not qualify: its entry may still arrive, so the
        // group waits.
        return $availability->activeSourceCountAt($group->primeiroTsMs) <= 1;
    }
}
