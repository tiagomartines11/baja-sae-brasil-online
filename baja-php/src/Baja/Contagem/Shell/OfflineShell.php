<?php
namespace Baja\Contagem\Shell;

use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Service\CarRoster;
use Baja\Model\Prova;

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
     * What both pages need to know about the prova, embedded in the page
     * for its very first paint and for a first load that never reaches the
     * sync endpoint. Once the device has synced, the sync answer replaces
     * every one of these (see default/js/contagem/page-info.js).
     *
     * @return array<string, mixed>
     */
    public static function provaConfig(Prova $prova): array
    {
        $settings = Settings::fromArray($prova->getParams()->contagem ?? null);
        $roster = CarRoster::forProva($prova);
        return [
            'versao' => self::assetVersion(),
            // Cars that take part (present and classified): tiles and lists.
            'carros' => self::carsForPage($roster->classified()),
            // Present but not classified: typing one gives a loud warning.
            'carros_nao_classificados' => self::carsForPage($roster->unclassified()),
            'janela_ms' => $settings->janelaMs,
            'abastecimento' => $settings->abastecimento,
            'simulacao' => $settings->permitirSimulacao,
            // Times on the page are shown in the event's time zone, not in
            // whatever zone the phone happens to be set to.
            'fuso' => date_default_timezone_get(),
        ];
    }

    /**
     * @param array<int, array{numero: int, nome: string, escola: string}> $cars keyed by car number
     * @return array<int, array{numero: int, nome: string}> a plain list, as the sync answer sends it
     */
    private static function carsForPage(array $cars): array
    {
        $list = [];
        foreach ($cars as $car) {
            $list[] = ['numero' => (int) $car['numero'], 'nome' => (string) $car['nome']];
        }
        return $list;
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
     * The always-visible bar: source name, race clock, official time,
     * connection state, entries waiting, last successful sync, who else is
     * on this source. Above it, the "SIMULAÇÃO" banner (the script shows it
     * while the prova allows simulation).
     */
    public static function printStatusBar(string $fonteNome, string $outrosRotulo): void
    {
        ?>
<div id="faixa-simulacao" class="faixa-simulacao" role="status" hidden>SIMULAÇÃO</div>
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
        <span class="detalhe detalhe-fila">Na fila: <b id="st-pendentes">0</b></span>
        <span class="detalhe" title="Último envio bem-sucedido">Enviado: <b id="st-ultimo-sync">—</b></span>
    </div>
    <?php // The server's clock, never the phone's: "sincronizando…" until the first answer of this page load. ?>
    <div class="barra-linha barra-horario">Horário oficial: <b id="st-horario-oficial" class="sincronizando">sincronizando…</b></div>
    <div class="barra-linha barra-outros"><?= htmlspecialchars($outrosRotulo) ?> <b id="st-outros">ninguém</b></div>
    <?php // Lies over the bar's last two lines, so that it never pushes the keypad or the tiles
          // down; a tap opens it over the page (status-bar.js). ?>
    <?php // role="alert" on the texts only: the toggle's label changes on every tap, and is no news. ?>
    <div id="aviso-fixo" class="aviso-fixo" hidden>
        <div id="aviso-fixo-textos" class="aviso-fixo-textos" role="alert"></div>
        <button type="button" id="btn-aviso-fixo" class="aviso-fixo-alternar" aria-expanded="false"
                aria-controls="aviso-fixo-textos">ver tudo</button>
        <?php // Retries in place, never reloads; the script shows it only when a retry can help. ?>
        <button type="button" id="btn-reconectar" class="botao-secundario" hidden>Tentar de novo</button>
    </div>
</header>
<section id="turno-encerrado" class="turno-encerrado" role="status" hidden>
    <h2>Turno encerrado</h2>
    <p>Este aparelho avisou que parou de contar e não está mais enviando nada.
       Tudo o que você registrou continua guardado aqui.</p>
    <button type="button" id="btn-retomar-turno" class="botao-registrar">Retomar contagem</button>
</section>
<noscript><p class="aviso-sem-script">Esta página precisa de JavaScript para funcionar.</p></noscript>
<p id="carregando" class="carregando">Abrindo…</p>
        <?php
    }

    /**
     * Printed first inside each page's footer: "Exportar meus dados" and,
     * beside it, "Encerrar meu turno" (the script asks before ending).
     */
    public static function printFooterButtons(): void
    {
        ?>
<button type="button" id="btn-exportar" class="botao-secundario">Exportar meus dados</button>
<button type="button" id="btn-encerrar-turno" class="botao-secundario">Encerrar meu turno</button>
        <?php
    }

    /**
     * Printed last inside each page's footer: the outcome of "end my shift"
     * and the lines that say whether this device is safe to count on.
     */
    public static function printFooterTools(): void
    {
        ?>
<p id="turno-mensagem" class="turno-mensagem" aria-live="polite" hidden></p>
<ul class="estado-aparelho">
    <li id="st-armazenamento">Armazenamento protegido: verificando…</li>
    <li id="st-copia-offline">Cópia offline: verificando…</li>
    <?php // Shown only where the browser cannot keep a second tab out. ?>
    <li id="st-aba-unica" hidden></li>
</ul>
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
