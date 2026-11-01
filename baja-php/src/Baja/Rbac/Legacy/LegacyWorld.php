<?php
declare(strict_types=1);

namespace Baja\Rbac\Legacy;

/**
 * What LegacyCode needs to know about the real tables to read a legacy
 * code: does this event, prova, queue or timer exist, and which programs
 * are at the top. Ids are compared exactly, case included, as the scope
 * tree does.
 */
interface LegacyWorld
{
    public function eventExists(string $eventoId): bool;

    public function provaExists(string $eventoId, string $provaId): bool;

    public function filaExists(string $eventoId, int $filaId): bool;

    public function timerExists(string $eventoId, int $timerId): bool;

    /** @return string[] programa_ids with no parent */
    public function topLevelProgramas(): array;
}
