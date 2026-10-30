<?php
declare(strict_types=1);

namespace Baja\Rbac\Legacy;

use PDO;
use Propel\Runtime\Connection\ConnectionInterface;

/**
 * LegacyWorld from the real tables. Each table is read once; ids are
 * compared in PHP, so exactly, case included (MySQL's own comparison
 * ignores case).
 */
final class DatabaseLegacyWorld implements LegacyWorld
{
    /** @var array<string, array<string, true>> table => "evento\0id" => true */
    private array $keys = [];

    public function __construct(private ConnectionInterface $con)
    {
    }

    public function eventExists(string $eventoId): bool
    {
        return isset($this->keys('evento', 'SELECT evento_id, \'\' FROM evento')[$eventoId . "\0"]);
    }

    public function provaExists(string $eventoId, string $provaId): bool
    {
        return isset($this->keys('prova', 'SELECT evento_id, prova_id FROM prova')[$eventoId . "\0" . $provaId]);
    }

    public function filaExists(string $eventoId, int $filaId): bool
    {
        return isset($this->keys('fila', 'SELECT evento_id, fila_id FROM fila')[$eventoId . "\0" . $filaId]);
    }

    public function timerExists(string $eventoId, int $timerId): bool
    {
        return isset($this->keys('timer', 'SELECT evento_id, timer_id FROM timer')[$eventoId . "\0" . $timerId]);
    }

    public function topLevelProgramas(): array
    {
        return $this->con->query('SELECT programa_id FROM programa WHERE parent_id IS NULL ORDER BY programa_id')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, true> */
    private function keys(string $table, string $sql): array
    {
        if (!isset($this->keys[$table])) {
            $this->keys[$table] = [];
            foreach ($this->con->query($sql)->fetchAll(PDO::FETCH_NUM) as [$evento, $id]) {
                $this->keys[$table][$evento . "\0" . $id] = true;
            }
        }
        return $this->keys[$table];
    }
}
