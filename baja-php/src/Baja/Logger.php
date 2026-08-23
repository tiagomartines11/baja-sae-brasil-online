<?php

namespace Baja;

use Baja\Util\Env;
use Monolog\Logger as MonologLogger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;

/**
 * Application logger service using Monolog.
 *
 * Container-native by default: JSON records are written to php://stdout and
 * collected by the container runtime (`docker compose logs baja-app`). Note
 * that under php-fpm they arrive on the container's stderr stream, not its
 * stdout — see createLogger(). File logging is opt-in via LOG_PATH and
 * exists for non-containerized hosts.
 *
 * Env vars:
 *   LOG_LEVEL  debug|info|notice|warning|error|critical (default: debug in
 *              dev, info in prod)
 *   LOG_PATH   directory for rotating file logs. Unset (the default) means
 *              stdout only — the correct behaviour under compose.
 *   LOG_STDOUT set to false to suppress the stdout handler. Only sensible
 *              when LOG_PATH is set, otherwise nothing is logged anywhere.
 */
class Logger
{
    private static ?MonologLogger $instance = null;
    private static ?string $logPath = null;
    private static string $logFile = 'app.log';

    /**
     * Get the logger instance (singleton).
     */
    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = self::createLogger();
        }
        return self::$instance;
    }

    /**
     * Create and configure the logger.
     */
    private static function createLogger(): MonologLogger
    {
        $logger = new MonologLogger('baja');

        $formatter = new JsonFormatter();
        $level = self::resolveLevel();

        // Optional rotating file handler (keeps 30 days). Only for hosts that
        // actually want files on disk — under compose, LOG_PATH is unset.
        $logDir = self::$logPath ?? Env::get('LOG_PATH');

        if ($logDir !== null && $logDir !== '') {
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }

            if (is_writable($logDir)) {
                $fileHandler = new RotatingFileHandler(
                    rtrim($logDir, '/') . '/' . self::$logFile,
                    30,  // maxFiles
                    $level
                );
                $fileHandler->setFormatter($formatter);
                $logger->pushHandler($fileHandler);
            }
        }

        // Primary sink. The VM gated this behind CLI-or-LOG_STDOUT, which under
        // php-fpm meant everything below ERROR was silently dropped.
        //
        // Under FPM this is not really stdout: the pool sets
        // catch_workers_output=yes, so worker output is re-emitted through
        // error_log=/proc/self/fd/2 and surfaces on the container's *stderr*.
        // That is also why there is no separate stderr handler for errors —
        // it would put two identical records on the same stream. On the CLI
        // (propel, cron) php://stdout is genuinely stdout.
        if (Env::getBool('LOG_STDOUT', true)) {
            $stdoutHandler = new StreamHandler('php://stdout', $level);
            $stdoutHandler->setFormatter($formatter);
            $logger->pushHandler($stdoutHandler);
        }

        return $logger;
    }

    /**
     * Resolve the minimum log level from LOG_LEVEL, falling back to the
     * environment default (debug in dev, info in prod).
     */
    private static function resolveLevel(): Level
    {
        $configured = Env::get('LOG_LEVEL');

        if ($configured !== null && $configured !== '') {
            try {
                // fromName() lowercases internally; an unknown name raises
                // UnhandledMatchError from its match expression.
                return Level::fromName((string) $configured);
            } catch (\UnhandledMatchError) {
                // A typo in LOG_LEVEL shouldn't take logging down with it.
            }
        }

        return Env::isProduction() ? Level::Info : Level::Debug;
    }

    /**
     * Configure log path, overriding LOG_PATH (call before first
     * getInstance() if needed). Pass null to fall back to the env var.
     */
    public static function setLogPath(?string $path): void
    {
        self::$logPath = $path;
        self::$instance = null; // Reset to recreate with new path
    }

    /**
     * Shortcut methods for common log levels.
     */
    public static function debug(string $message, array $context = []): void
    {
        self::getInstance()->debug($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::getInstance()->info($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::getInstance()->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::getInstance()->error($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::getInstance()->critical($message, $context);
    }
}
