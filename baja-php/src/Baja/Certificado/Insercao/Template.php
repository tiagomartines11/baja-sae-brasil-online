<?php

namespace Baja\Certificado\Insercao;

use Baja\Model\User;

/**
 * Page chrome for the certificate insertion pages.
 *
 * Two things it has to do at once, and they pull against each other.
 *
 * It follows the public certificate pages rather than the juiz pages, because
 * the artefact is an SAE BRASIL certificate and the surrounding pages should
 * look like one. The palette is the same one sampled from the certificate
 * mask, and no Baja marks appear.
 *
 * But it must not be mistakable for them. One of these pages mints
 * certificates and the other is a form anyone on the internet can reach, and
 * they would otherwise be the same page with different fields. So: a band
 * across the top naming this as issuance, the logged-in user in the header,
 * and a way back to /juiz that the public pages do not have. Somebody landing
 * mid-task should know in one glance which surface they are on.
 */
final class Template
{
    public static function printHeader(string $titulo, ?User $usuario = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        // These pages are staff-only and carry participants' names. Nothing
        // here should be indexed, cached by a proxy, or left in the back
        // button after a logout.
        header('X-Robots-Tag: noindex, nofollow', true);
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Referrer-Policy: no-referrer');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta http-equiv="Content-Language" content="pt-br">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= self::e($titulo) ?> — Emissão de certificados</title>
    <style>
        /*
         * Sampled from certificado/img/certificado.png, same as the public
         * pages. The mask came from SAE BRASIL and is not ours to
         * reinterpret. --sae-emissao is the one colour those pages do not
         * have: it exists only to mark this surface as the one that writes.
         */
        :root {
            --sae-navy: #004185;
            --sae-navy-deep: #003E7C;
            --sae-grey: #6a7078;
            --sae-rule: #C4C6CB;
            --sae-light: #f4f5f7;
            --sae-border: #d7dbe0;
            --sae-emissao: #8a5a00;
            --erro: #a3231d;
            --aviso: #8a5a00;
            --ok: #1a7f45;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: var(--sae-light);
            color: #1c2226;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 16px;
            line-height: 1.5;
        }
        .faixa {
            background: var(--sae-emissao);
            color: #fff;
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 6px 16px;
            text-align: center;
        }
        .wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px 48px; }
        .brand {
            padding: 4px 0 16px;
            border-bottom: 3px solid var(--sae-navy);
            margin-bottom: 20px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        .brand img { display: block; width: 100%; max-width: 240px; height: auto; }
        .quem { font-size: 13px; color: var(--sae-grey); text-align: right; }
        .quem strong { color: var(--sae-navy-deep); display: block; font-size: 15px; }
        .card {
            background: #fff;
            border: 1px solid var(--sae-border);
            border-radius: 6px;
            padding: 24px;
            margin-bottom: 16px;
        }
        h1 { font-size: 22px; margin: 0 0 4px; color: var(--sae-navy-deep); }
        h2 { font-size: 18px; margin: 0 0 12px; color: var(--sae-navy-deep); }
        p { margin: 0 0 12px; }
        .muted { color: var(--sae-grey); font-size: 14px; }
        label { display: block; font-weight: bold; margin-bottom: 4px; }
        input[type=text], select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--sae-border);
            border-radius: 4px;
            font-size: 16px;
            font-family: inherit;
            background: #fff;
        }
        textarea { font-family: monospace; font-size: 13px; line-height: 1.4; }
        .field { margin-bottom: 16px; }
        .campos { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        @media (max-width: 620px) { .campos { grid-template-columns: 1fr; } }
        button {
            background: var(--sae-navy);
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 12px 20px;
            font-size: 16px;
            cursor: pointer;
        }
        button.secundario { background: #fff; color: var(--sae-navy); border: 1px solid var(--sae-navy); }
        button.perigo { background: var(--erro); }
        .btn {
            display: inline-block;
            background: var(--sae-navy);
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            padding: 10px 18px;
            margin-right: 8px;
        }
        .btn-secondary { background: #fff; color: var(--sae-navy); border: 1px solid var(--sae-navy); }
        .voltar { font-size: 14px; margin-bottom: 16px; }
        .voltar a { color: var(--sae-navy); }
        .alerta { border-left: 4px solid var(--sae-navy); padding: 10px 14px; margin-bottom: 16px; background: #fff; }
        .alerta.erro  { border-color: var(--erro);  color: var(--erro); }
        .alerta.aviso { border-color: var(--aviso); color: var(--aviso); }
        .alerta.ok    { border-color: var(--ok);    color: var(--ok); }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--sae-border); vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; color: var(--sae-grey); }
        code { font-family: monospace; font-size: 13px; background: var(--sae-light); padding: 1px 4px; border-radius: 3px; }
        /*
         * The batch page states what a batch is as a description list, and
         * without these it renders on the browser's defaults — a 40px indent
         * and no label styling — in the middle of a page that has neither.
         * Same rules as the public pages, so a dt reads as a label there and
         * here.
         */
        dl { margin: 0; }
        dt { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--sae-grey); margin-top: 12px; }
        dd { margin: 0; font-size: 16px; overflow-wrap: anywhere; }

        /*
         * Multi-select with a filter box, built out of <details> so that it
         * degrades honestly: with no JavaScript the panel still opens, every
         * checkbox is still there, and only the filter box goes inert. A
         * custom widget that needs script to be usable at all would be worse
         * than the native <select multiple> it replaces.
         */
        .multi { border: 1px solid var(--sae-border); border-radius: 4px; background: #fff; }
        .multi > summary {
            list-style: none; cursor: pointer; padding: 10px;
            display: flex; justify-content: space-between; gap: 8px;
        }
        .multi > summary::-webkit-details-marker { display: none; }
        .multi > summary::after { content: "▾"; color: var(--sae-grey); }
        .multi[open] > summary::after { content: "▴"; }
        .multi-painel { border-top: 1px solid var(--sae-border); padding: 8px; }
        .multi-filtro { margin-bottom: 8px; }
        .multi-lista { max-height: 220px; overflow-y: auto; }
        .multi-lista label {
            display: block; font-weight: normal; padding: 3px 2px; margin: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .multi-lista label:hover { background: var(--sae-light); }
        .multi-acoes { border-top: 1px solid var(--sae-border); padding-top: 6px; margin-top: 6px; font-size: 13px; }
        .multi-acoes button { padding: 2px 8px; font-size: 13px; }
        .doc-composto { display: flex; gap: 8px; }
        .doc-composto select { width: auto; flex: 0 0 auto; }

        /*
         * Tables on a phone.
         *
         * These pages are used on a paddock as often as at a desk, and the
         * screenshots that prompted this came from somebody's Android. A table
         * of identity data does not fit 360px, and the failure is the worst
         * kind: the last column runs off the right edge with no way to reach
         * it, because the page does not scroll sideways and the table has no
         * scroller of its own.
         *
         * Three treatments, because there are three kinds of content here and
         * one rule for all of them gets at least two wrong. Which one a table
         * gets is a decision about what the table is, so it is spelled on the
         * table: .cartoes, .blocos, or a .rolagem wrapper.
         *
         * What none of them do is shrink the type. Staff are reading names and
         * document numbers off this, sometimes outdoors, and a layout that
         * only fits at 11px does not fit. If a card is still too long the
         * answer is fewer rows per page, not smaller text.
         */
        /*
         * 1. Genuinely tabular and genuinely wide: the pasted spreadsheet on
         *    the column-assignment step. Reshaping it would defeat its
         *    purpose — the operator is matching what is here against what is
         *    open in Excel — so it scrolls sideways inside its own box, and
         *    only inside it.
         *
         * The gradients are the scroll affordance. The two white ones are
         * painted with background-attachment: local so they travel with the
         * content; the two shadows are attached to the box and stay put. A
         * shadow is therefore covered by its white neighbour exactly when
         * there is nothing more to see in that direction, which is what makes
         * "there is more to the right" visible without a script measuring
         * anything.
         */
        .rolagem {
            overflow-x: auto;
            overflow-y: hidden;
            background:
                linear-gradient(to right, #fff 30%, rgba(255, 255, 255, 0)) left center,
                linear-gradient(to left,  #fff 30%, rgba(255, 255, 255, 0)) right center,
                radial-gradient(farthest-side at 0    50%, rgba(0, 0, 0, 0.20), rgba(0, 0, 0, 0)) left center,
                radial-gradient(farthest-side at 100% 50%, rgba(0, 0, 0, 0.20), rgba(0, 0, 0, 0)) right center;
            background-repeat: no-repeat;
            background-size: 44px 100%, 44px 100%, 16px 100%, 16px 100%;
            background-attachment: local, local, scroll, scroll;
        }
        /* Wider than the box when it needs to be — that is the point of it. */
        .rolagem > table { width: auto; min-width: 100%; }
        /*
         * The one interactive control on that page. Left to the column widths
         * it truncates to "E˅", "Nor˅", "Funçã˅", which is unreadable, and it
         * is the field the whole step exists to fill in. Wide enough for
         * "Passaporte" and the "— ignorar —" placeholder.
         */
        .rolagem select { min-width: 11rem; }

        /*
         * 2. Not tabular at all: the review rows, where the last column is a
         *    set of radio buttons with a sentence explaining each. Squeezed
         *    into a fifth of the width that renders as a one-word-wide ribbon
         *    of text several screens tall. The problem is not the column
         *    width — a form is not tabular data — so on a narrow screen each
         *    row becomes a block: the identity fields run together as one
         *    summary line, and the decision gets the whole width underneath.
         */
        @media (max-width: 760px) {
            table.blocos, table.blocos tbody { display: block; }
            table.blocos thead { display: none; }
            table.blocos tr {
                display: flex;
                flex-wrap: wrap;
                align-items: baseline;
                gap: 0 10px;
                background: #fff;
                border: 1px solid var(--sae-border);
                border-radius: 6px;
                padding: 12px 14px;
                margin-bottom: 10px;
            }
            table.blocos td {
                display: block;
                border: 0;
                padding: 0;
                overflow-wrap: anywhere;
            }
            table.blocos td.bloco-num { color: var(--sae-grey); font-weight: bold; }
            table.blocos td.bloco-num::before { content: "#"; }
            table.blocos td.bloco-nome { font-weight: bold; color: var(--sae-navy-deep); }
            /*
             * The fields ran under their own headings while this was a table.
             * On one line they need something between them, or "AB25 Fulano de
             * Tal competidor" reads as one string.
             */
            table.blocos td + td:not(.bloco-decisao)::before {
                content: "·";
                color: var(--sae-rule);
                margin-right: 8px;
            }
            /* Full width, on its own, under a rule that separates it from the
               row it is about. */
            table.blocos td.bloco-decisao {
                flex: 1 0 100%;
                margin-top: 10px;
                padding-top: 10px;
                border-top: 1px solid var(--sae-border);
            }
            table.blocos td.bloco-decisao label { padding: 4px 0; }
            table.blocos td.bloco-decisao input[type=radio] { width: 18px; height: 18px; }
        }

        /*
         * 3. A list of records: the search results. One card per certificate,
         *    the name as its heading, the rest as label/value pairs. The
         *    labels come from data-rotulo rather than a second copy of the
         *    markup, so the header row and the card labels cannot drift.
         *
         * Cards are taller than rows, and that is the trade being made:
         * vertical scrolling is what a phone is for and horizontal overflow is
         * a dead end.
         */
        @media (max-width: 760px) {
            table.cartoes, table.cartoes tbody { display: block; }
            table.cartoes thead { display: none; }
            /*
             * Two columns for one row of the grid, so the checkbox sits at the
             * top-left with the name beside it and everything else runs full
             * width beneath. Bulk selection is the reason this page has
             * checkboxes at all, so the checkbox has to stay where a thumb
             * finds it.
             */
            table.cartoes tr {
                display: grid;
                grid-template-columns: auto 1fr;
                align-items: start;
                background: #fff;
                border: 1px solid var(--sae-border);
                border-radius: 6px;
                padding: 12px 14px;
                margin-bottom: 10px;
            }
            table.cartoes td {
                display: block;
                grid-column: 1 / -1;
                border: 0;
                padding: 6px 0 0;
                overflow-wrap: anywhere;
            }
            table.cartoes td[data-rotulo]::before {
                content: attr(data-rotulo);
                display: block;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                color: var(--sae-grey);
            }
            /*
             * The gutter belongs to the checkbox, not to the grid. Most of
             * these tables have no checkbox column at all, and a column-gap
             * would indent their heading by 10px against the fields under it,
             * because the empty first track still takes its gap.
             */
            table.cartoes td.cartao-marcar { grid-column: 1; grid-row: 1; padding: 0 10px 0 0; }
            table.cartoes td.cartao-titulo {
                grid-column: 2;
                grid-row: 1;
                padding-top: 0;
                font-size: 17px;
                font-weight: bold;
                color: var(--sae-navy-deep);
            }
            table.cartoes input[type=checkbox] { width: 20px; height: 20px; margin: 3px 0 0; }
        }

        /*
         * The document number, short and long.
         *
         * Both forms are in the markup and the viewport picks one. Not a
         * privacy control — the page needs the `certificados` permission and
         * the full number is one rotation away — but a phone held at arm's
         * length in a public place is read by more people than a laptop at a
         * desk, and the last three digits are enough to tell one row from the
         * next.
         */
        .doc-curto { display: none; }
        @media (max-width: 760px) {
            .doc-longo { display: none; }
            .doc-curto { display: inline; letter-spacing: 0.04em; }
        }
    </style>
</head>
<body>
<div class="faixa">Emissão de certificados — os dados salvos aqui viram certificados oficiais</div>
<div class="wrap">
    <header class="brand">
        <img src="/img/sae-brasil-wordmark.png" alt="SAE BRASIL" />
        <?php if ($usuario !== null): ?>
        <div class="quem">
            conectado como
            <strong><?= self::e((string) $usuario->getUsername()) ?></strong>
        </div>
        <?php endif; ?>
    </header>
    <nav class="voltar">
        <a href="index.php">&larr; Entrada de dados</a>
        &emsp;&middot;&emsp;
        <a href="certificados_busca.php">Consultar</a>
        &emsp;&middot;&emsp;
        <a href="lotes.php">Lotes</a>
        &emsp;&middot;&emsp;
        <a href="certificados.php">Novo certificado</a>
        &emsp;&middot;&emsp;
        <a href="certificados_lote.php">Inserção em lote</a>
        &emsp;&middot;&emsp;
        <a href="certificados_nome.php">Corrigir um nome</a>
    </nav>
        <?php
    }

    public static function printFooter(): void
    {
        ?>
</div>
</body>
</html>
        <?php
    }

    /**
     * The behaviour for the <details> multi-selects.
     *
     * Progressive enhancement only: without it the panels still open, every
     * option is still there and still submits, and only the filter box goes
     * inert. Emitted from here rather than copied into each page that uses
     * one, because two copies of a filter's rules is how they stop agreeing.
     */
    public static function printScriptMultiSelect(): void
    {
        ?>
<script>
document.querySelectorAll('[data-multi]').forEach(function (bloco) {
    var filtro = bloco.querySelector('[data-filtro]');
    var resumo = bloco.querySelector('[data-resumo]');
    var limpar = bloco.querySelector('[data-limpar]');
    var labels = Array.prototype.slice.call(bloco.querySelectorAll('.multi-lista label'));
    var vazio  = resumo.textContent.trim();

    function atualizarResumo() {
        var marcados = labels
            .filter(function (l) { return l.querySelector('input').checked; })
            .map(function (l) { return l.querySelector('input').value; });

        if (marcados.length === 0)     resumo.textContent = vazio;
        else if (marcados.length <= 3) resumo.textContent = marcados.join(', ');
        else                           resumo.textContent = marcados.length + ' selecionados';
    }

    filtro.addEventListener('input', function () {
        var termo = filtro.value.toLowerCase();
        labels.forEach(function (l) {
            // A checked option always stays visible. Filtering something out
            // of sight while it is still part of the search is how somebody
            // ends up not understanding their own results.
            var visivel = l.querySelector('input').checked
                || l.textContent.toLowerCase().indexOf(termo) !== -1;
            l.style.display = visivel ? '' : 'none';
        });
    });

    // Typing in the filter must not submit the form.
    filtro.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); }
    });

    bloco.addEventListener('change', atualizarResumo);

    limpar.addEventListener('click', function () {
        labels.forEach(function (l) { l.querySelector('input').checked = false; });
        atualizarResumo();
    });

    atualizarResumo();
});
</script>
        <?php
    }

    /**
     * The page a user without `certificados` gets.
     *
     * A rendered page rather than Session::permissionCheck()'s bare die(),
     * because most people reaching /juiz are judges and this is a normal
     * thing for them to hit. It says what permission is missing and who
     * grants it, so the reader's next step is asking the right person rather
     * than assuming something is broken.
     */
    public static function negarAcesso(?User $usuario = null): void
    {
        http_response_code(403);
        self::printHeader('Sem permissão', $usuario);
        ?>
    <div class="card">
        <h1>Você não tem acesso a esta página</h1>
        <p>
            A emissão de certificados exige a permissão <code>certificados</code>,
            que é concedida separadamente das permissões de juiz.
        </p>
        <p class="muted">
            Se você precisa emitir certificados, peça a um administrador do sistema
            para conceder essa permissão à sua conta.
        </p>
    </div>
        <?php
        self::printFooter();
        exit();
    }

    public static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    }
}
