<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * The prova's lock could not be obtained in time: somebody else is writing.
 * Endpoints turn this into "503, try again", which every client of this
 * feature already does on its own.
 */
final class LockBusy extends \RuntimeException
{
}
