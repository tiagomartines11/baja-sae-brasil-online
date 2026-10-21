<?php
namespace Baja\Juiz;

/**
 * Peças comuns das páginas de ajuda (ajuda_prova.php, ajuda_resultado.php,
 * ajuda_menu.php): mesmo cabeçalho/tabelas azuis das telas de administração,
 * mais o mínimo de estilo para blocos de código, que o reset do
 * theme.blue.css deixa sem margem nem fonte monoespaçada.
 */
class Ajuda
{
    static function printHeader($titulo, $voltarHref, array $sumario = []) {
        Template::printHeader("Ajuda - " . $titulo, false);
        ?>
        <style>
            .ajuda td { text-align: left; line-height: 20px; }
            .ajuda td p { margin: 6px 0; }
            .ajuda td ul { margin: 6px 0 6px 20px; }
            .ajuda code { font-family: Consolas, "Courier New", monospace; background: #ebf2fa; padding: 0 3px; }
            .ajuda pre { font-family: Consolas, "Courier New", monospace; font-size: 13px; line-height: 17px; background: #f5f8fc; border: 1px solid #cdcdcd; padding: 8px; margin: 6px 0; white-space: pre-wrap; overflow-wrap: anywhere; }
            .ajuda td.chave { white-space: nowrap; font-weight: bold; width: 1%; }
            .ajuda td.tipo { white-space: nowrap; width: 1%; color: #555; }
            .ajuda .aviso { background: #fff4d6; border-left: 4px solid #e0a800; padding: 6px 8px; margin: 6px 0; }
            .ajuda .exemplo-titulo { font-weight: bold; margin-top: 10px; }
            .ajuda th a { color: white; }
        </style>
        <div class="ajuda" style="max-width: 1000px; margin: 0 auto;">
        <table class="tablesorter" style="margin-bottom: 20px;">
            <thead>
                <tr style="height: 50px">
                    <th class="sorter-false" style="vertical-align: middle;">
                        <span style="float:left"><a href="<?= $voltarHref ?>" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                        <span style="font-size: 28px;">Ajuda: <?= $titulo ?></span>
                    </th>
                </tr>
            </thead>
            <?php if (count($sumario) > 0) { ?>
            <tbody>
                <tr><td>
                    <strong>Nesta página:</strong>
                    <?php
                    $links = [];
                    foreach ($sumario as $ancora => $texto) {
                        $links[] = '<a href="#' . $ancora . '">' . $texto . '</a>';
                    }
                    echo implode(' &middot; ', $links);
                    ?>
                </td></tr>
            </tbody>
            <?php } ?>
        </table>
        <?php
    }

    static function printFooter() {
        ?>
        </div>
        <?php
        Template::printFooter();
    }

    /**
     * Abre uma seção: tabela com título e, se houver colunas, a linha de
     * cabeçalho da tabela de opções.
     */
    static function secao($ancora, $titulo, array $colunas = []) {
        $span = max(1, count($colunas));
        ?>
        <table id="<?= $ancora ?>" class="tablesorter" style="margin-bottom: 20px;">
            <thead>
                <tr><th colspan="<?= $span ?>" class="sorter-false" style="font-size: 20px; height: 34px;"><?= $titulo ?></th></tr>
                <?php if (count($colunas) > 0) { ?>
                <tr>
                    <?php foreach ($colunas as $c) { ?><th class="sorter-false"><?= $c ?></th><?php } ?>
                </tr>
                <?php } ?>
            </thead>
            <tbody>
        <?php
    }

    static function fimSecao() {
        ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Linha de uma tabela de opções: chave, tipo, descrição (HTML).
     */
    static function opcao($chave, $tipo, $descricao) {
        echo '<tr><td class="chave"><code>' . $chave . '</code></td><td class="tipo">' . $tipo . '</td><td>' . $descricao . '</td></tr>';
    }

    /**
     * Linha de uma tabela de duas colunas: chave e descrição (HTML).
     */
    static function item($chave, $descricao) {
        echo '<tr><td class="chave"><code>' . $chave . '</code></td><td>' . $descricao . '</td></tr>';
    }

    /**
     * Bloco de exemplo em JSON, mostrado com a formatação em que foi escrito.
     * O texto é validado, de modo que um exemplo malformado aparece como erro
     * na própria página em vez de ensinar um JSON que o editor não aceitaria.
     */
    static function exemplo($json, $titulo = null) {
        $texto = trim($json);
        if (json_decode($texto) === null && json_last_error() !== JSON_ERROR_NONE) {
            $texto = 'EXEMPLO INVÁLIDO (' . json_last_error_msg() . "):\n" . $texto;
        }
        return ($titulo ? '<div class="exemplo-titulo">' . $titulo . '</div>' : '')
            . '<pre>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    /**
     * Bloco de código que não é JSON (fórmulas, trechos de texto).
     */
    static function codigo($texto) {
        return '<pre>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}
