<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * One lock per prova, held while raw data is stored and the engine result
 * is written out.
 *
 * Without it two uploads arriving together could each run the engine on a
 * different snapshot and the slower one would overwrite contagem_volta and
 * the published scores with the older picture.
 *
 * MySQL's GET_LOCK is used rather than a Redis lock because it is released
 * by the server when the connection dies: a PHP process killed half way
 * through can never leave a race locked.
 */
final class ProvaLock
{
    /** An engine run takes well under a second; ten is generous and still fails before nginx gives up. */
    private const WAIT_SECONDS = 10;

    /**
     * How many times each lock is currently held by this process. Lets
     * code that already holds the lock call something that takes it again.
     *
     * @var array<string, int>
     */
    private static array $depthByName = [];

    private string $name;

    public function __construct(Prova $prova)
    {
        // Lock names are global to the MySQL server, hence the prefix.
        $this->name = 'contagem_' . $prova->getEventoId() . '_' . $prova->getProvaId();
    }

    /**
     * @return mixed whatever $work returns
     * @throws \RuntimeException when the lock cannot be obtained in time
     */
    public function run(callable $work)
    {
        $this->acquire();
        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    private function acquire(): void
    {
        $depth = self::$depthByName[$this->name] ?? 0;
        if ($depth === 0) {
            $statement = Propel::getWriteConnection('resultados')->prepare('SELECT GET_LOCK(:name, :wait)');
            $statement->execute([':name' => $this->name, ':wait' => self::WAIT_SECONDS]);
            $granted = (int) $statement->fetchColumn();
            $statement->closeCursor();
            if ($granted !== 1) {
                throw new \RuntimeException('Não foi possível obter a trava da contagem ' . $this->name);
            }
        }
        self::$depthByName[$this->name] = $depth + 1;
    }

    private function release(): void
    {
        $depth = (self::$depthByName[$this->name] ?? 1) - 1;
        self::$depthByName[$this->name] = $depth;
        if ($depth === 0) {
            unset(self::$depthByName[$this->name]);
            $statement = Propel::getWriteConnection('resultados')->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute([':name' => $this->name]);
            $statement->closeCursor();
        }
    }
}
