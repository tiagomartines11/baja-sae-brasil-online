<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use Baja\Contagem\Engine\Data\Ocorrencia;
use Baja\Contagem\Service\Clock;
use Baja\Contagem\Service\ContagemService;
use Baja\Model\Prova;
use InvalidArgumentException;

/**
 * What race control checks before the START of a race is recorded or moved.
 *
 * The start is the one event that decides which entries belong to the race
 * at all: an entry before it does not count. So every action that can move
 * it (a new start, a correction, cancelling a correction or a second start,
 * restoring one) goes through the same question here, asked while the count
 * lock is held.
 */
final class StartRules
{
    /** Form field: "I was told the test preset is applied, and want to start all the same". */
    public const CAMPO_CONFIRMAR_PREDEFINICAO = 'confirmar_predefinicao';
    /**
     * Form field carrying the instant of the click on "Largar agora" through
     * a question: the click is the start time, and the seconds the person
     * takes to answer must not be added to it.
     */
    public const CAMPO_INSTANTE = 'instante_ms';
    /** A click instant older than this is not believed: the start is then typed as a time. */
    private const MAX_AGE_OF_CLICK_MS = 10 * 60 * 1000;

    /**
     * What a new or corrected start has to pass, apart from what it does to
     * the entries (see requireMoveConfirmed()).
     *
     * @param int|null $currentStartMs the start in force, if the race has one
     * @param array<string, mixed> $post
     */
    public static function checkStart(ContagemService $service, ?int $currentStartMs, int $tsMs, bool $isCorrection, array $post): void
    {
        if ($tsMs > Clock::nowMs()) {
            // The usual cause is a wrong day or "PM" for "AM" in the time
            // field. A start in the future would put every entry typed until
            // then before the start: no car would have a single pass.
            throw new InvalidArgumentException(
                'O horário da largada (' . Pagina::formatClock($tsMs) . ') está no futuro. Confira a data e a hora.'
            );
        }
        if ($isCorrection) {
            return;
        }
        if ($service->hasSavedSettings() && !self::said($post, self::CAMPO_CONFIRMAR_PREDEFINICAO)) {
            // A rehearsal's settings left in place: the matching window is
            // a second or two, far too short for people typing by hand.
            throw new ConfirmationNeeded(
                'A PREDEFINIÇÃO DE TESTE está aplicada: os tempos da contagem estão ajustados para um ensaio e não são os da corrida de verdade. '
                    . 'Se esta é a corrida de verdade, volte e use "Restaurar valores salvos" em Fontes e configurações antes de largar. '
                    . 'Se é um ensaio, confirme.',
                self::CAMPO_CONFIRMAR_PREDEFINICAO,
                'É um ensaio: largar'
            );
        }
        if ($currentStartMs !== null && !self::said($post, RaceControl::CAMPO_CONFIRMAR)) {
            // Catches the page that was opened before somebody else started
            // the race, and the second click of a double click.
            throw new ConfirmationNeeded(
                'A corrida já tem largada (' . Pagina::formatClock($currentStartMs) . '). Confirme para substituir o horário por '
                    . Pagina::formatClock($tsMs) . '.',
                RaceControl::CAMPO_CONFIRMAR,
                'Substituir a largada'
            );
        }
    }

    /**
     * Asks before any action that leaves entries BEFORE the start: a new
     * start, a corrected one, a cancelled correction (the older time comes
     * back), a cancelled second start, a restored one.
     *
     * Moving the start a few seconds later is how the start pack used to
     * vanish: every car lost a lap and nothing said so. So the start is
     * worked out twice, without and with the row about to be stored, and
     * the entries between the two are counted HERE, under the count lock.
     * The page never sends this confirmation on its own: a number it
     * computed when it was drawn says nothing about the entries typed since.
     *
     * @param Ocorrencia[] $ocorrencias the events stored so far
     * @param array<string, mixed> $post
     */
    public static function requireMoveConfirmed(Prova $prova, array $ocorrencias, Ocorrencia $newRow, array $post): void
    {
        $startBefore = RaceEventRules::startMs($ocorrencias);
        $startAfter = RaceEventRules::startMs(array_merge($ocorrencias, [$newRow]));
        if ($startAfter === null || $startAfter === $startBefore) {
            return;
        }
        $affected = RaceEventRules::entriesLeftBeforeStart($prova, $startBefore, $startAfter);
        if ($affected['registros'] === 0 || self::said($post, RaceControl::CAMPO_CONFIRMAR_REGISTROS)) {
            return;
        }
        $move = $startBefore === null
            ? 'A largada ficaria em ' . Pagina::formatClock($startAfter) . '.'
            : 'A largada passaria de ' . Pagina::formatClock($startBefore) . ' para ' . Pagina::formatClock($startAfter) . '.';
        throw new ConfirmationNeeded(
            $move . ' ' . ucfirst(RaceEventRules::describeEntriesLeftOut($affected))
                . ' ficariam ANTES da largada e não contariam (aparecem na revisão como "antes da largada"). Confirme se é isso mesmo.',
            RaceControl::CAMPO_CONFIRMAR_REGISTROS,
            $startBefore === null ? 'Registrar a largada mesmo assim' : 'Mover a largada mesmo assim'
        );
    }

    /**
     * The instant of the first click on "Largar agora", sent back by the
     * form that answers a question about it. Null when absent or not
     * believable (in the future, or long ago).
     *
     * @param array<string, mixed> $post
     */
    public static function clickInstant(array $post): ?int
    {
        $raw = $post[self::CAMPO_INSTANTE] ?? null;
        if (!is_string($raw) || !ctype_digit($raw) || strlen($raw) > 15) {
            return null;
        }
        $clickMs = (int) $raw;
        $nowMs = Clock::nowMs();
        return $clickMs <= $nowMs && $nowMs - $clickMs <= self::MAX_AGE_OF_CLICK_MS ? $clickMs : null;
    }

    /** @param array<string, mixed> $post */
    private static function said(array $post, string $field): bool
    {
        return ($post[$field] ?? '') === '1';
    }
}
