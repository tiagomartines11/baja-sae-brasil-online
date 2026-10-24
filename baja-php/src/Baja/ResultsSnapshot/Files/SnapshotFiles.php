<?php
declare(strict_types=1);

namespace Baja\ResultsSnapshot\Files;

use RuntimeException;

/**
 * The snapshot folder on disk: tests/results-snapshots/<EVENTO>/<file>.json.
 *
 * Files are compared and kept as a map "relative path => file contents",
 * always sorted by path, so the order in which they were produced never
 * matters.
 */
final class SnapshotFiles
{
    /**
     * The one way a snapshot document becomes text: pretty-printed, accents
     * and slashes left as they are (so the HTML in a cell stays readable),
     * and a final newline (so git does not complain).
     *
     * @param array<string, mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @param array<string, array<string, mixed>> $documents path => document
     * @return array<string, string> path => file contents, sorted by path
     */
    public static function encodeAll(array $documents): array
    {
        $files = array_map([self::class, 'encode'], $documents);
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Every snapshot file under $root, optionally only those of some events.
     *
     * @param list<string>|null $onlyEvents
     * @return array<string, string> path => file contents, sorted by path
     */
    public static function read(string $root, ?array $onlyEvents = null): array
    {
        $files = [];
        foreach (glob(rtrim($root, '/') . '/*/*.json') ?: [] as $file) {
            $path = basename(dirname($file)) . '/' . basename($file);
            if (self::inScope($path, $onlyEvents)) {
                $files[$path] = (string)file_get_contents($file);
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Makes $root hold exactly $files (within the chosen events): writes
     * them all and removes snapshot files that are no longer produced, such
     * as one for a resultado deleted from the seed.
     *
     * @param array<string, string> $files path => file contents
     * @param list<string>|null     $onlyEvents
     */
    public static function replace(string $root, array $files, ?array $onlyEvents = null): void
    {
        foreach (array_keys(self::read($root, $onlyEvents)) as $path) {
            if (!array_key_exists($path, $files)) {
                unlink($root . '/' . $path);
            }
        }
        foreach ($files as $path => $contents) {
            $target = $root . '/' . $path;
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
                throw new RuntimeException('Could not create ' . dirname($target));
            }
            // Unchanged files are not rewritten, so their dates stay put.
            if (!is_file($target) || file_get_contents($target) !== $contents) {
                file_put_contents($target, $contents);
            }
        }
        foreach (glob(rtrim($root, '/') . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
            if ((glob($folder . '/*') ?: []) === []) {
                rmdir($folder);
            }
        }
    }

    /** @param list<string>|null $onlyEvents */
    private static function inScope(string $path, ?array $onlyEvents): bool
    {
        return $onlyEvents === null || in_array(explode('/', $path)[0], $onlyEvents, true);
    }
}
