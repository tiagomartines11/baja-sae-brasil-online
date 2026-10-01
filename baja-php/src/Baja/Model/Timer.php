<?php

namespace Baja\Model;

use Baja\Model\Base\Timer as BaseTimer;

/**
 * Skeleton subclass for representing a row from the 'timer' table.
 *
 *
 *
 * You should add additional methods to this class to meet the
 * application requirements.  This class will only be generated as
 * long as it does not already exist in the output directory.
 */
class Timer extends BaseTimer
{
    /** Entries kept in the log column; older ones are dropped. */
    const LOG_MAX = 500;

    const KEY_LENGTH = 6;

    /** No 0/O, 1/I/L: the key is read off one screen and typed into another. */
    const KEY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    const SOUND_EXTENSIONS = ['wav', 'mp3', 'ogg', 'm4a', 'webm'];

    /** Subfolder of juiz/sons for sounds made on the server (text to speech); not in git. */
    const GENERATED_DIR = 'gerados';

    /**
     * @return object|null
     */
    public function getConfig()
    {
        return json_decode(parent::getConfig() ?? '');
    }

    /**
     * @param object|array|null $v
     * @return $this|Timer
     */
    public function setConfig($v)
    {
        return parent::setConfig($v !== null ? json_encode($v) : null);
    }

    /**
     * @return array|null
     */
    public function getState()
    {
        return json_decode(parent::getState() ?? '', true);
    }

    /**
     * @param array|null $v
     * @return $this|Timer
     */
    public function setState($v)
    {
        return parent::setState($v !== null ? json_encode($v) : null);
    }

    /**
     * @return array
     */
    public function getLog()
    {
        $log = json_decode(parent::getLog() ?? '', true);
        return is_array($log) ? $log : [];
    }

    /**
     * @param string   $quem  username, or 'chave' for access-key use
     * @param string   $acao
     * @param array    $dados extra fields stored alongside
     * @param int|null $ts    when it happened (epoch seconds); defaults to now
     * @return $this|Timer
     */
    public function appendLog($quem, $acao, array $dados = [], $ts = null)
    {
        $log = $this->getLog();
        $log[] = array_merge(['t' => date('c', $ts ?? time()), 'quem' => $quem, 'acao' => $acao], $dados);
        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, -self::LOG_MAX);
        }
        return parent::setLog(json_encode($log, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return string
     */
    public function getNome()
    {
        $config = $this->getConfig();
        return isset($config->nome) && $config->nome !== '' ? (string)$config->nome : 'Timer ' . $this->getTimerId();
    }

    /**
     * @return $this|Timer
     */
    public function generateAccessKey()
    {
        do {
            $key = '';
            for ($i = 0; $i < self::KEY_LENGTH; $i++) {
                $key .= self::KEY_ALPHABET[random_int(0, strlen(self::KEY_ALPHABET) - 1)];
            }
        } while (TimerQuery::create()->findOneByAccessKey($key));

        return $this->setAccessKey($key);
    }

    /**
     * Reduces whatever was posted to the shape the usage page relies on.
     *
     * @param mixed $raw decoded JSON
     * @return array|null null when there is no usable sequence in it
     */
    public static function normalizeConfig($raw)
    {
        $raw = json_decode(json_encode($raw), true);
        if (!is_array($raw) || !isset($raw['etapas']) || !is_array($raw['etapas'])) return null;

        $sons = self::availableSounds();
        $som = fn($v) => (is_string($v) && in_array($v, $sons, true)) ? $v : null;

        $etapas = [];
        foreach ($raw['etapas'] as $e) {
            if (!is_array($e)) return null;
            $nome = trim((string)($e['nome'] ?? ''));
            $tempo = (int)($e['tempo'] ?? 0);
            if ($nome === '' || $tempo < 1) return null;
            $etapas[] = [
                'nome' => $nome,
                'tempo' => $tempo,
                'som_inicio' => $som($e['som_inicio'] ?? null),
                'som_fim' => $som($e['som_fim'] ?? null),
            ];
        }
        if (!count($etapas)) return null;

        return [
            'nome' => trim((string)($raw['nome'] ?? '')),
            'loop' => !empty($raw['loop']),
            'etapas' => $etapas,
        ];
    }

    /**
     * @return string[] file names under juiz/sons that can be played
     */
    public static function availableSounds()
    {
        $sons = [];
        foreach (['', self::GENERATED_DIR . '/'] as $sub) {
            foreach (@scandir(self::soundsDir() . '/' . $sub) ?: [] as $f) {
                if (in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), self::SOUND_EXTENSIONS, true)) $sons[] = $sub . $f;
            }
        }
        sort($sons);
        return $sons;
    }

    /**
     * @return string
     */
    public static function soundsDir()
    {
        return __DIR__ . '/../../../juiz/sons';
    }
}
