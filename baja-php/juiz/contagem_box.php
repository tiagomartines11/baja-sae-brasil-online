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

$config = OfflineShell::provaConfig($prova) + [
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
];

OfflineShell::printDocumentStart('Box - ' . $prova->getNome(), $config, 'box.js');
OfflineShell::printStatusBar($fonte->getNome(), 'Também registrando o box:');
?>
<main id="conteudo" class="conteudo" hidden>
    <?php // The page opens here: which area (only when the prova logs the fueling station),
          // then entry or exit. box.js shows one question at a time. ?>
    <section id="box-escolha" class="entrada box-escolha" hidden>
        <div id="escolha-area" hidden>
            <h2>Onde você está?</h2>
            <div class="botoes-box">
                <button type="button" class="botao-box botao-area" data-area="box">Box</button>
                <button type="button" class="botao-box botao-area" data-area="abastecimento">Abastecimento</button>
            </div>
        </div>
        <div id="escolha-sentido" hidden>
            <h2>O que você vai registrar?</h2>
            <div class="botoes-box">
                <button type="button" class="botao-box botao-entrou" data-sentido="entrada">Entrada</button>
                <button type="button" class="botao-box botao-saiu" data-sentido="saida">Saída</button>
            </div>
            <p class="texto-apoio">Depois de escolher, cada toque em um carro registra na hora.
               Dá para trocar a qualquer momento.</p>
        </div>
    </section>

    <section id="box-registro" class="entrada" aria-label="Registrar entrada ou saída" hidden>
        <nav id="chave-area" class="chave" aria-label="Área" hidden>
            <button type="button" data-area="box">Box</button>
            <button type="button" data-area="abastecimento">Abastecimento</button>
        </nav>
        <nav id="chave-sentido" class="chave" aria-label="Entrada ou saída">
            <button type="button" data-sentido="entrada">Entrada</button>
            <button type="button" data-sentido="saida">Saída</button>
        </nav>
        <p id="box-instrucao" class="box-instrucao"></p>
        <p id="box-mensagem" class="box-mensagem" aria-live="assertive"></p>

        <h2 id="titulo-primeiros"></h2>
        <div id="blocos-primeiros" class="blocos"></div>
        <p id="primeiros-vazio" class="texto-apoio" hidden></p>

        <?php // Never hidden while it has cars: the person at the other end may have missed one. ?>
        <div id="grupo-outros">
            <h2 id="titulo-outros"></h2>
            <div id="blocos-outros" class="blocos blocos-pequenos"></div>
        </div>

        <form id="form-box" class="form-teclado form-outro-numero" autocomplete="off">
            <input id="campo-numero" class="campo-numero" type="text" inputmode="numeric" pattern="[0-9]*"
                   maxlength="4" enterkeyhint="done" autocomplete="off" aria-label="Outro número de carro"
                   placeholder="Outro nº">
            <button type="submit" class="botao-secundario">Registrar</button>
        </form>
    </section>

    <section class="recentes">
        <h2>Últimos registros</h2>
        <ol id="lista-recentes" class="lista-recentes"></ol>
    </section>

    <footer class="rodape">
        <?php OfflineShell::printFooterButtons(); ?>
        <a class="link-voltar" href="contagem.php?p=<?= htmlspecialchars(rawurlencode($provaId)) ?>">Voltar</a>
        <?php OfflineShell::printFooterTools(); ?>
    </footer>
</main>
<?php
OfflineShell::printDocumentEnd();
