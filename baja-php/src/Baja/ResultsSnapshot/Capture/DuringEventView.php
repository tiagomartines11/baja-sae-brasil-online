<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Capture;

use Baja\Model\Evento;
use Baja\Model\EventoQuery;
use ReflectionProperty;

/**
 * Shows a finished event to a page as if it were still running: spoilers
 * on (spoilers = 1), not finished (finalizado = 0). Nothing is written to
 * the database.
 *
 * The pages ask EventoQuery::getCurrentEvent() for the event, and that
 * method keeps the Evento it loaded in a private static property so it is
 * only loaded once per request. Putting our own Evento object in that
 * property, with the two flags changed in memory only, is enough: every
 * part of the page that asks about spoilers or finalizado asks through it.
 *
 * The property is private and the class must not change for a test, so it
 * is set by reflection. The object is never saved; the session is also
 * read-only (ReadOnlyDatabase), so even an accidental save would fail.
 */
final class DuringEventView
{
    /** Loads the event, flips the flags in memory, and makes it the current event. */
    public static function show(string $eventoId): Evento
    {
        $evento = EventoQuery::create()->findPk($eventoId);
        if ($evento === null) {
            throw new \RuntimeException('Event not found: ' . $eventoId);
        }
        return self::showObject($evento);
    }

    /** The part of show() that needs no database, so the tests can call it directly. */
    public static function showObject(Evento $evento): Evento
    {
        $evento->setSpoilers(true);
        $evento->setFinalizado(false);
        self::setCurrentEvent($evento);
        return $evento;
    }

    /** Puts an Evento (or null, to forget it) into EventoQuery's cache. */
    public static function setCurrentEvent(?Evento $evento): void
    {
        (new ReflectionProperty(EventoQuery::class, '_currentEvent'))->setValue(null, $evento);
    }
}
