<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Shell\OfflineShell;
use Baja\Contagem\Sync\DeviceTokens;
use Baja\Session;

// Pit log page shell. Like the count page: permission check, skeleton and
// configuration here; behaviour in default/js/contagem/box.js.

if (!isset($_REQUEST['p'])) { header("Location: index.php"); exit; }

$prova = ContagemAccess::findLapsProva((string) $_REQUEST['p']);
if (!$prova) { header("Location: index.php"); exit; }

if (!ContagemAccess::canUsePitLog($prova)) {
    die("Você não tem acesso a essa página");
}

$provaId = $prova->getProvaId();

// Pit devices are bound to the prova's internal "Box" source (created on
// first use). Its id keeps this page's local log apart from any count page
// opened on the same device.
$fonte = DeviceTokens::boxSourceFor($prova);

$config = [
    'tipo' => 'box',
    'evento_id' => $prova->getEventoId(),
    'prova_id' => $provaId,
    'usuario' => Session::getCurrentUser()->getUsername(),
    'fonte' => ['id' => (int) $fonte->getFonteId(), 'nome' => $fonte->getNome()],
    'endpoints' => [
        'sync' => 'contagem_sync.php',
        'dispositivo' => 'contagem_dispositivo.php?p=' . rawurlencode($provaId) . '&box=1',
        'service_worker' => 'contagem_sw.php',
    ],
    'versao' => OfflineShell::assetVersion(),
    'carros' => OfflineShell::presentCars($prova->getEventoId()),
    'janela_ms' => null,
];

OfflineShell::printDocumentStart('Box - ' . $prova->getNome(), $config, 'box.js');
OfflineShell::printStatusBar($fonte->getNome(), 'Também registrando o box:');
?>
<main id="conteudo" class="conteudo" hidden>
    <section class="entrada" aria-label="Registrar entrada ou saída do box">
        <form id="form-box" class="form-teclado" autocomplete="off">
            <input id="campo-numero" class="campo-numero" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="4" autocomplete="off" aria-label="Número do carro" placeholder="Nº">
        </form>
        <div id="box-equipe" class="box-equipe">&nbsp;</div>
        <div class="botoes-box">
            <button type="button" id="btn-entrou" class="botao-box botao-entrou" disabled>Entrou</button>
            <button type="button" id="btn-saiu" class="botao-box botao-saiu" disabled>Saiu</button>
        </div>
        <p id="box-mensagem" class="box-mensagem" aria-live="assertive"></p>
    </section>

    <section class="no-box">
        <h2>No box agora</h2>
        <div id="lista-no-box" class="blocos"></div>
        <p id="no-box-vazio" class="texto-apoio">Nenhum carro no box.</p>
    </section>

    <section class="todos-carros">
        <h2>Carros</h2>
        <div id="blocos" class="blocos blocos-pequenos"></div>
    </section>

    <section class="recentes">
        <h2>Últimos registros</h2>
        <ol id="lista-recentes" class="lista-recentes"></ol>
    </section>

    <footer class="rodape">
        <button type="button" id="btn-exportar" class="botao-secundario">Exportar meus dados</button>
        <a class="link-voltar" href="contagem.php?p=<?= htmlspecialchars(rawurlencode($provaId)) ?>">Voltar</a>
    </footer>
</main>
<?php
OfflineShell::printDocumentEnd();
