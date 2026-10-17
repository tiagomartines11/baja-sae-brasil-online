<?php
namespace Baja\Juiz;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Contagem\Review\Pagina;

// Review screen. This file is only the shell: everything on it is drawn by
// the modules in /js/contagem/revisao*.js from what contagem_estado.php answers.

$prova = Pagina::provaOrRedirect();
ContagemAccess::requireController($prova);

$h = [Pagina::class, 'h'];
$simulationIsOn = Pagina::simulationBanner($prova) !== '';
$presetIsApplied = Pagina::testPresetBanner($prova) !== '';

// /js/ is cached for 30 days. The entry module gets a version in its URL,
// but the modules it imports are named inside the files, so the import map
// below points each of them at a versioned URL too.
$moduleDirectory = dirname(__DIR__) . '/default/js/contagem';
$importMap = [];
foreach (glob($moduleDirectory . '/revisao*.js') ?: [] as $moduleFile) {
    $url = '/js/contagem/' . basename($moduleFile);
    $importMap[$url] = $url . '?v=' . filemtime($moduleFile);
}
$entryModule = $importMap['/js/contagem/revisao.js'] ?? '/js/contagem/revisao.js';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="google" content="notranslate">
    <link rel="icon" href="img/baja.png" type="image/png">
    <title>Revisão - <?= $h($prova->getNome()) ?></title>
    <?= Pagina::stylesheetTag() ?>
    <script type="importmap"><?= json_encode(['imports' => (object) $importMap], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
</head>
<body class="revisao"
      data-prova="<?= $h($prova->getProvaId()) ?>"
      data-csrf="<?= $h(Pagina::csrfToken($prova)) ?>"
      data-usuario="<?= $h(Pagina::username()) ?>">

<?= Pagina::navigation($prova, 'Revisão') ?>

<?php // Permanent while the prova accepts simulated entries; the script keeps it in step with the setting. ?>
<p class="contagem-simulacao<?= $simulationIsOn ? '' : ' oculto' ?>" id="faixa-simulacao" role="alert">
    <strong>SIMULAÇÃO</strong> Esta prova aceita passagens simuladas. Desligue "Permitir simulação" em Fontes e configurações antes da corrida de verdade.
</p>
<?php // Permanent while the test preset is applied; kept in step with the server like the one above. ?>
<p class="contagem-simulacao contagem-predefinicao<?= $presetIsApplied ? '' : ' oculto' ?>" id="faixa-predefinicao" role="alert">
    <strong>PREDEFINIÇÃO DE TESTE APLICADA</strong> Os tempos da contagem estão ajustados para um ensaio e NÃO são os da corrida de verdade.
    Antes da corrida, use "Restaurar valores salvos" em Fontes e configurações.
</p>
<header class="revisao-barra" id="barra-estado" aria-live="polite"></header>
<?php // Publishing off or points that look wrong, in full; the bar has the short red pill. ?>
<div class="revisao-aviso oculto" id="aviso-pontuacao" role="alert"></div>
<?php // Filled by the script when the recalculation had to correct a published count. ?>
<div class="revisao-aviso oculto" id="aviso-reconciliacao" role="alert"></div>
<div class="revisao-aviso oculto" id="aviso-conexao" role="alert"></div>
<div class="revisao-aviso oculto" id="aviso-mensagem" role="status"></div>

<nav class="revisao-abas" id="abas">
    <button type="button" data-aba="fila" class="ativa">Alertas</button>
    <button type="button" data-aba="alerta">Detalhe</button>
    <button type="button" data-aba="tabela">Carros</button>
</nav>

<main class="revisao-zonas" data-aba-ativa="fila">
    <?php // Always on screen, on every tab: it follows the race, and a flag or a car scrolls it to what was picked. ?>
    <section class="revisao-zona zona-linha" data-zona="linha" aria-labelledby="linha-titulo">
        <div class="linha-cabecalho">
            <h2 id="linha-titulo">Linha do tempo</h2>
            <span class="linha-situacao" id="linha-situacao"></span>
            <span class="linha-aviso" id="linha-aviso" role="status"></span>
            <button type="button" class="linha-botao" id="linha-so-carro" aria-pressed="true" hidden>Só o carro</button>
            <button type="button" class="linha-botao" id="linha-agora" aria-pressed="true">Agora</button>
        </div>
        <div class="linha-corpo">
            <div class="raias linha-raias" id="linha-raias"></div>
            <div class="linha-extras" id="linha-extras"></div>
        </div>
        <details class="linha-legenda">
            <summary>Legenda</summary>
            <p class="contagem-dica">
                Clique em um registro para agir sobre ele. Verde = conta. Com um alerta ou carro aberto: contorno âmbar grosso = registro do alerta,
                contorno âmbar fino = outros registros do mesmo carro, apagados = outros carros.
                Riscado = anulado: borda vermelha pela revisão, "C" = pelo próprio contador, pontilhado = repetição anulada automaticamente.
                Canto azul = conta porque um revisor confirmou; traço azul embaixo = o horário da passagem é deste registro ("Usar o tempo");
                âmbar com borda grossa = volta curta demais que conta porque a revisão confirmou (o registro oferece desfazer a confirmação).
                Borda pontilhada escura = um contador só, ainda não conta. Verde-azulado em itálico = fonte observadora (só comparada, nunca conta).
                "0" no canto = passagem zero (não é volta); tracejado = não conta (antes da largada, janela de largada ou depois do encerramento);
                tracejado vermelho com fundo = passagem retida (não conta até um revisor decidir);
                "~" = horário estimado; "?" = horário desconhecido (fora da linha do tempo).
            </p>
        </details>
    </section>

    <section class="revisao-zona zona-fila" data-zona="fila">
        <h2>Alertas <span id="contagem-alertas"></span></h2>
        <ul class="fila" id="fila-abertos"></ul>
        <details id="resolvidos">
            <summary>Resolvidos automaticamente <span id="contagem-resolvidos"></span></summary>
            <ul class="fila" id="fila-resolvidos"></ul>
        </details>
    </section>

    <section class="revisao-zona zona-alerta" data-zona="alerta">
        <div id="painel-detalhe">
            <p class="vazio">Escolha um alerta na fila, um carro na tabela ou um registro na linha do tempo.</p>
        </div>
    </section>

    <section class="revisao-zona zona-tabela" data-zona="tabela">
        <h2>Carros</h2>
        <div class="rolagem"><table class="carros" id="tabela-carros"></table></div>

        <h2>Fontes</h2>
        <div id="estatisticas-fontes"></div>

        <h2>Histórico de decisões <span class="historico-resumo" id="historico-resumo" aria-live="polite"></span></h2>
        <?php // In the markup, not drawn by the script: the screen is redrawn every second and the filters must not flicker or lose what is typed. ?>
        <form class="historico-filtros" id="historico-filtros" role="search" aria-label="Filtrar o histórico de decisões">
            <label>Carro <input type="number" name="carro" min="0" step="1" inputmode="numeric" class="campo-carro" list="carros-validos"></label>
            <label>Contador <select name="fonte"><option value="">todos</option></select></label>
            <label>Quem <select name="quem"><option value="">todos</option></select></label>
            <label>Tipo <select name="tipo"><option value="">todos</option></select></label>
            <label class="so-em-vigor"><input type="checkbox" name="soEmVigor"> Só em vigor</label>
            <button type="button" name="limpar" disabled>Limpar filtros</button>
        </form>
        <div class="rolagem historico-rolagem"><table class="historico" id="tabela-historico"></table></div>
        <div id="historico-aviso"></div>
    </section>
</main>

<noscript><p class="contagem-erro">A tela de revisão precisa de JavaScript.</p></noscript>
<script type="module" src="<?= $h($entryModule) ?>"></script>
</body>
</html>
