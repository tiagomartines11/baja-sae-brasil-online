<?php

namespace Baja\Model;

use Baja\Model\Base\EventoQuery as BaseEventoQuery;

/**
 * Skeleton subclass for performing query and update operations on the 'evento' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 *
 */
class EventoQuery extends BaseEventoQuery
{
    /** @var Evento */
    private static $_currentEvent = null;

    static function getCurrentEvent() {
        if (!self::$_currentEvent) self::$_currentEvent = EventoQuery::create()->findOneByEventoId($_SERVER['REDIRECT_EVENT']);
        return self::$_currentEvent;
    }
}
