<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Source;
use Baja\Contagem\Engine\Settings;
use Baja\Contagem\Shell\OfflineShell;
use Baja\Model\ContagemFonteQuery;
use Baja\Session;

// Count page shell. Everything the counter does happens in the browser
// (default/js/contagem/contar.js); this file only checks who is asking and
// prints the skeleton plus the configuration the script needs.

if (!isset($_REQUEST['p'], $_REQUEST['f'])) { header("Location: index.php"); exit; }

$prova = ContagemAccess::findLapsProva((string) $_REQUEST['p']);
if (!$prova) { header("Location: index.php"); exit; }

$fonte = ContagemFonteQuery::create()
    ->filterByEventoId($prova->getEventoId())
    ->filterByProvaId($prova->getProvaId())
    ->findOneByFonteId((int) $_REQUEST['f']);

// The same sentence Session::permissionCheck() uses. A source that does not
// exist gets the same answer as one the user may not open, so the page does
// not reveal which source ids exist.
if (!$fonte || $fonte->getTipo() !== Source::TIPO_MANUAL || !ContagemAccess::canCountOn($fonte)) {
    die("Você não tem acesso a essa página");
}

$provaId = $prova->getProvaId();
$settings = Settings::fromArray($prova->getParams()->contagem ?? null);

$config = [
    'tipo' => 'contar',
    'evento_id' => $prova->getEventoId(),
    'prova_id' => $provaId,
    'usuario' => Session::getCurrentUser()->getUsername(),
    'fonte' => ['id' => (int) $fonte->getFonteId(), 'nome' => $fonte->getNome()],
    'endpoints' => [
        'sync' => 'contagem_sync.php',
        'dispositivo' => 'contagem_dispositivo.php?p=' . rawurlencode($provaId) . '&f=' . (int) $fonte->getFonteId(),
        'service_worker' => 'contagem_sw.php',
    ],
    'versao' => OfflineShell::assetVersion(),
    'carros' => OfflineShell::presentCars($prova->getEventoId()),
    'janela_ms' => $settings->janelaMs,
];

OfflineShell::printDocumentStart($fonte->getNome() . ' - ' . $prova->getNome(), $config, 'contar.js');
OfflineShell::printStatusBar($fonte->getNome(), 'Também contando aqui:');
?>
<main id="conteudo" class="conteudo" hidden>
    <section id="destaque" class="destaque" aria-live="assertive">
        <div id="destaque-numero" class="destaque-numero">&nbsp;</div>
        <div id="destaque-equipe" class="destaque-equipe">Digite o número do carro que passou</div>
        <div id="destaque-avisos" class="destaque-avisos"></div>
    </section>

    <section class="entrada" aria-label="Registrar passagem">
        <div id="modo-teclado" hidden>
            <form id="form-teclado" class="form-teclado" autocomplete="off">
                <input id="campo-numero" class="campo-numero" type="text" inputmode="numeric" pattern="[0-9]*"
                       maxlength="4" enterkeyhint="done" autocomplete="off" aria-label="Número do carro">
                <button type="submit" class="botao-registrar">Registrar</button>
            </form>
        </div>
        <div id="modo-teclado_tela" hidden>
            <div id="visor-numero" class="visor-numero" aria-live="polite">&nbsp;</div>
            <div id="teclado-tela" class="teclado-tela"></div>
        </div>
        <div id="modo-blocos" hidden>
            <div id="blocos" class="blocos"></div>
            <p id="blocos-vazio" class="texto-apoio" hidden>A lista de carros ainda não chegou. Use o teclado.</p>
        </div>
    </section>

    <nav class="seletor-modos" aria-label="Modo de entrada">
        <button type="button" data-modo="teclado">Teclado</button>
        <button type="button" data-modo="teclado_tela">Teclado na tela</button>
        <button type="button" data-modo="blocos">Blocos</button>
    </nav>

    <section class="recentes">
        <h2>Últimos registros</h2>
        <ol id="lista-recentes" class="lista-recentes"></ol>
    </section>

    <footer class="rodape">
        <button type="button" id="btn-exportar" class="botao-secundario">Exportar meus dados</button>
        <label class="opcao"><input type="checkbox" id="opt-som"> Som</label>
        <label class="opcao"><input type="checkbox" id="opt-vibrar"> Vibrar</label>
        <a class="link-voltar" href="contagem.php?p=<?= htmlspecialchars(rawurlencode($provaId)) ?>">Voltar</a>
        <?php OfflineShell::printFooterTools(); ?>
    </footer>
</main>
<?php
OfflineShell::printDocumentEnd();
