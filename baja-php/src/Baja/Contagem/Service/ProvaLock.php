<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

use Baja\Model\Prova;
use Propel\Runtime\Propel;

/**
 * A named lock for one prova.
 *
 * Two are used:
 *
 * - the COUNT lock (the default): held while raw data is stored and the
 *   official count of the affected cars is written and published. Without
 *   it two uploads arriving together could each count from a different
 *   picture, and the slower one would overwrite the newer result.
 * - the RECALCULATION lock ("recalculo"): held by whoever is running the
 *   slow, whole-race recalculation, so that only one runs at a time. Nobody
 *   waits for it: a request that finds it taken serves the previous result.
 *
 * MySQL's GET_LOCK is used rather than a Redis lock because the server
 * releases it when the connection dies: a PHP process killed half way
 * through can never leave a race locked.
 */
final class ProvaLock
{
    /** For actions a person is waiting on (a decision, a race-control button). */
    public const WAIT_FOR_A_PERSON_SECONDS = 10;

    /**
     * For uploads. A phone retries by itself a few seconds later, so it is
     * better to tell it "busy" quickly than to keep a PHP worker waiting.
     */
    public const WAIT_FOR_AN_UPLOAD_SECONDS = 2;

    /**
     * How many times each lock is currently held by this process. Lets
     * code that already holds the lock call something that takes it again.
     *
     * @var array<string, int>
     */
    private static array $depthByName = [];

    private string $name;

    public function __construct(Prova $prova, string $purpose = '')
    {
        // Lock names are global to the MySQL server, hence the prefix.
        $this->name = 'contagem_' . $prova->getEventoId() . '_' . $prova->getProvaId() . ($purpose !== '' ? '_' . $purpose : '');
    }

    /**
     * @return mixed whatever $work returns
     * @throws LockBusy when the lock cannot be obtained within $waitSeconds
     */
    public function run(callable $work, int $waitSeconds = self::WAIT_FOR_A_PERSON_SECONDS)
    {
        if (!$this->acquire($waitSeconds)) {
            throw new LockBusy('Não foi possível obter a trava da contagem ' . $this->name);
        }
        try {
            return $work();
        } finally {
            $this->release();
        }
    }

    /**
     * Runs $work only if the lock is free right now.
     *
     * @return bool true when $work ran
     */
    public function runIfFree(callable $work): bool
    {
        if (!$this->acquire(0)) {
            return false;
        }
        try {
            $work();
        } finally {
            $this->release();
        }
        return true;
    }

    private function acquire(int $waitSeconds): bool
    {
        $depth = self::$depthByName[$this->name] ?? 0;
        if ($depth === 0) {
            $statement = Propel::getWriteConnection('resultados')->prepare('SELECT GET_LOCK(:name, :wait)');
            $statement->execute([':name' => $this->name, ':wait' => $waitSeconds]);
            $row = $statement->fetch(\PDO::FETCH_NUM);
            $statement->closeCursor();
            if (!is_array($row) || (int) $row[0] !== 1) {
                return false;
            }
        }
        self::$depthByName[$this->name] = $depth + 1;
        return true;
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
