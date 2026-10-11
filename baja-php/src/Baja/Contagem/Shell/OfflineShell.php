<?php
namespace Baja\Contagem\Shell;

/**
 * The HTML document shared by the count page and the pit page, and the asset
 * list their service worker caches.
 *
 * These pages must reload with no network, so they are standalone documents:
 * no Template::printHeader (jQuery, tablesorter and Google Analytics are all
 * things an offline page cannot wait for), only this feature's own style
 * sheet and ES modules.
 */
final class OfflineShell
{
    /**
     * Sent by the page shells and looked for by the service worker. A login
     * redirect or an "access denied" answer does not carry it, so the worker
     * never stores one of those in place of the page.
     */
    public const SHELL_HEADER = 'X-Contagem-Shell';

    private const SCRIPT_FOLDER = 'js/contagem';
    private const STYLE_SHEET = 'css/contagem.css';

    /** Scripts that live in the folder but are not part of the offline shell. */
    private const SERVICE_WORKER_SCRIPT = 'service-worker.js';
    private const REVIEW_SCRIPT_PREFIX = 'revisao';

    private static function assetRoot(): string
    {
        return dirname(__DIR__, 4) . '/default';
    }

    public static function serviceWorkerSourcePath(): string
    {
        return self::assetRoot() . '/' . self::SCRIPT_FOLDER . '/' . self::SERVICE_WORKER_SCRIPT;
    }

    /**
     * Relative URLs (as the pages reference them) of every file the offline
     * shell needs, without version.
     *
     * @return string[]
     */
    public static function assetFiles(): array
    {
        $files = [self::STYLE_SHEET];
        $scripts = glob(self::assetRoot() . '/' . self::SCRIPT_FOLDER . '/*.js') ?: [];
        sort($scripts);
        foreach ($scripts as $path) {
            $name = basename($path);
            if ($name === self::SERVICE_WORKER_SCRIPT || strpos($name, self::REVIEW_SCRIPT_PREFIX) === 0) {
                continue;
            }
            $files[] = self::SCRIPT_FOLDER . '/' . $name;
        }
        return $files;
    }

    /**
     * Changes whenever any shell file changes. nginx serves /js/ and /css/
     * with a 30-day cache, so every asset URL carries this as "?v=", and the
     * service worker names its cache after it.
     */
    public static function assetVersion(): string
    {
        $fingerprint = '';
        $paths = array_merge(self::assetFiles(), [self::SCRIPT_FOLDER . '/' . self::SERVICE_WORKER_SCRIPT]);
        foreach ($paths as $relativePath) {
            $path = self::assetRoot() . '/' . $relativePath;
            $fingerprint .= $relativePath . ':' . (int) @filemtime($path) . ':' . (int) @filesize($path) . ';';
        }
        return substr(sha1($fingerprint), 0, 12);
    }

    public static function versioned(string $relativeUrl): string
    {
        return $relativeUrl . '?v=' . self::assetVersion();
    }

    /** @return string[] what the service worker downloads on install */
    public static function versionedAssetFiles(): array
    {
        return array_map([self::class, 'versioned'], self::assetFiles());
    }

    /**
     * Modules import each other by plain relative path ("./local-store.js"),
     * which would escape the "?v=" and hit the 30-day cache. The import map
     * tells the browser to load each of them with the version attached, so a
     * deploy can never mix new and old modules. (A browser too old for import
     * maps ignores it and still works; it just relies on the service worker.)
     */
    private static function importMapJson(): string
    {
        $imports = [];
        foreach (self::assetFiles() as $relativeUrl) {
            if (substr($relativeUrl, -3) === '.js') {
                $imports['./' . $relativeUrl] = './' . self::versioned($relativeUrl);
            }
        }
        return self::jsonForScriptTag(['imports' => $imports]);
    }

    /** JSON that cannot break out of the <script> element it is printed in. */
    private static function jsonForScriptTag(array $data): string
    {
        return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Car list embedded in the page for its very first paint, and for a first
     * load that never reaches the sync endpoint. Once the device has synced,
     * the `valid_cars` of the sync answer replaces it.
     *
     * @return array<int, array{numero: int, nome: string}>
     */
    public static function presentCars(string $eventoId): array
    {
        $equipes = \Baja\Model\EquipeQuery::create()
            ->filterByEventoId($eventoId)
            ->filterByPresente(true)
            ->orderByEquipeId()
            ->find();
        $carros = [];
        foreach ($equipes as $equipe) {
            $carros[] = ['numero' => (int) $equipe->getEquipeId(), 'nome' => $equipe->getEquipe()];
        }
        return $carros;
    }

    /**
     * Prints everything up to and including <body>.
     *
     * @param string $title       page title, plain text
     * @param array  $config      handed to the page's module as JSON
     * @param string $entryModule file name inside js/contagem, e.g. "contar.js"
     */
    public static function printDocumentStart(string $title, array $config, string $entryModule): void
    {
        header(self::SHELL_HEADER . ': 1');
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="google" content="notranslate">
    <meta name="theme-color" content="#000000">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="icon" href="img/baja.png" type="image/png">
    <link rel="stylesheet" href="<?= htmlspecialchars(self::versioned(self::STYLE_SHEET)) ?>">
    <script type="importmap"><?= self::importMapJson() ?></script>
    <script type="application/json" id="contagem-config"><?= self::jsonForScriptTag($config) ?></script>
    <script type="module" src="<?= htmlspecialchars(self::versioned(self::SCRIPT_FOLDER . '/' . $entryModule)) ?>"></script>
</head>
<body>
        <?php
    }

    /**
     * The always-visible bar: source name, race clock, connection state,
     * entries waiting, last successful sync, who else is on this source.
     */
    public static function printStatusBar(string $fonteNome, string $outrosRotulo): void
    {
        ?>
<header class="barra-status">
    <div class="barra-linha barra-principal">
        <strong id="st-fonte" class="fonte-nome"><?= htmlspecialchars($fonteNome) ?></strong>
        <span class="relogio-corrida">
            <span id="st-relogio" class="relogio">--:--:--</span>
            <span id="st-estado-corrida" class="estado-corrida"></span>
        </span>
    </div>
    <div class="barra-linha barra-detalhes">
        <span id="st-conexao" class="conexao conexao-conectando">Conectando…</span>
        <span class="detalhe">Na fila: <b id="st-pendentes">0</b></span>
        <span class="detalhe">Último envio: <b id="st-ultimo-sync">—</b></span>
    </div>
    <div class="barra-linha barra-outros"><?= htmlspecialchars($outrosRotulo) ?> <b id="st-outros">ninguém</b></div>
</header>
<div id="aviso-fixo" class="aviso-fixo" role="alert" hidden>
    <p id="aviso-fixo-texto"></p>
    <button type="button" id="btn-reconectar" class="botao-secundario">Tentar reconectar</button>
</div>
<noscript><p class="aviso-fixo">Esta página precisa de JavaScript para funcionar.</p></noscript>
<p id="carregando" class="carregando">Abrindo…</p>
        <?php
    }

    public static function printDocumentEnd(): void
    {
        ?>
</body>
</html>
        <?php
    }
}
