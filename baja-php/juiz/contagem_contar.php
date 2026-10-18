<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Engine\Data\Source;
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

$config = OfflineShell::provaConfig($prova) + [
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
];

OfflineShell::printDocumentStart($fonte->getNome() . ' - ' . $prova->getNome(), $config, 'contar.js');
OfflineShell::printStatusBar($fonte->getNome(), 'Também contando aqui:');
?>
<main id="conteudo" class="conteudo" hidden>
    <?php // Fixed size whatever it says (contar-destaque.js): the keys and tiles below must never move.
          // The instruction follows the entry mode. ?>
    <section id="destaque" class="destaque destaque-instrucao" aria-live="assertive">
        <div id="destaque-numero" class="destaque-numero">&nbsp;</div>
        <div id="destaque-equipe" class="destaque-equipe">Digite o número</div>
        <div id="destaque-aviso" class="destaque-aviso destaque-aviso-vazio">
            <span id="destaque-aviso-curto" aria-hidden="true"></span>
            <span id="destaque-aviso-completo" class="so-leitor"></span>
        </div>
        <?php // "Informar número" on a "??": drawn OVER the box, same size, so nothing below moves
              // (correction-mode.js). "Passou um carro" records a "??" at once and ends it. ?>
        <div id="correcao" class="correcao" hidden>
            <p id="correcao-texto" class="correcao-texto"></p>
            <div class="correcao-botoes">
                <button type="button" id="btn-correcao-cancelar" class="botao-correcao-cancelar">Cancelar</button>
                <button type="button" id="btn-correcao-passou" class="botao-correcao-passou">Passou um carro</button>
            </div>
        </div>
    </section>

    <section class="entrada" aria-label="Registrar passagem">
        <div id="modo-teclado" hidden>
            <form id="form-teclado" class="form-teclado" autocomplete="off">
                <?php // "??": the number could not be read. Also the "?" key. On the left, away from Registrar. ?>
                <button type="button" id="btn-ilegivel" class="botao-ilegivel" aria-label="Não consegui ler o número">??</button>
                <input id="campo-numero" class="campo-numero" type="text" inputmode="numeric" pattern="[0-9]*"
                       maxlength="4" enterkeyhint="done" autocomplete="off" aria-label="Número do carro">
                <button type="submit" class="botao-registrar">Registrar</button>
            </form>
        </div>
        <div id="modo-teclado_tela" hidden>
            <div class="visor-linha">
                <button type="button" id="btn-ilegivel-tela" class="botao-ilegivel" aria-label="Não consegui ler o número">??</button>
                <div id="visor-numero" class="visor-numero" aria-live="polite">&nbsp;</div>
            </div>
            <div id="teclado-tela" class="teclado-tela"></div>
        </div>
        <div id="modo-blocos" hidden>
            <div id="blocos" class="blocos"></div>
            <p id="blocos-vazio" class="texto-apoio" hidden>A lista de carros ainda não chegou. Use o teclado.</p>
            <?php // Two taps: the car as it approaches, then "Passou" as it crosses. Stays at the
                  // bottom of the screen while the tiles scroll, under the thumb. ?>
            <div id="blocos-dois-toques" class="passou-painel">
                <div class="selecionado" aria-live="polite">
                    <span id="selecionado-numero" class="selecionado-numero">–</span>
                    <span id="selecionado-equipe" class="selecionado-equipe">Toque no carro que vem chegando</span>
                </div>
                <button type="button" id="btn-passou" class="botao-passou" disabled>Passou</button>
            </div>
            <label class="opcao opcao-um-toque"><input type="checkbox" id="opt-um-toque"> Um toque marca a passagem</label>
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
        <?php OfflineShell::printFooterButtons(); ?>
        <label class="opcao"><input type="checkbox" id="opt-som"> Som</label>
        <label class="opcao"><input type="checkbox" id="opt-vibrar"> Vibrar</label>
        <a class="link-voltar" id="link-voltar" href="contagem.php?p=<?= htmlspecialchars(rawurlencode($provaId)) ?>">Voltar</a>
        <?php // The list of sources is not in the offline copy: shown instead of the link while offline (status-bar.js). ?>
        <span class="link-voltar voltar-sem-conexao" id="voltar-sem-conexao" hidden>Voltar: sem conexão</span>
        <?php OfflineShell::printFooterTools(); ?>
    </footer>
</main>
<?php
OfflineShell::printDocumentEnd();
