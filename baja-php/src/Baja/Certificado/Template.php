<?php

namespace Baja\Certificado;

/**
 * Page chrome for the public certificate pages.
 *
 * Separate from Baja\Juiz\Template rather than reusing it, for two reasons.
 * That template is Baja-branded, and this system issues certificates for
 * several SAE BRASIL student programs, not just Baja. It also emits a Google
 * Analytics tag on every page, and these pages carry a participant's full
 * name; with no analytics there is no non-essential cookie here, so no consent
 * banner is required and none should be added.
 */
final class Template
{
    public static function printHeader(string $title): void
    {
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta http-equiv="Content-Language" content="pt-br">
    <?php /* Belt and braces with the X-Robots-Tag header sent by Http. */ ?>
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        /*
         * Sampled from certificado/img/certificado.png rather than chosen.
         * The mask came from SAE BRASIL and is not ours to reinterpret, so the
         * pages that sit around it use its own colours: #004185 is the frame
         * and the "Certificado" script, #003E7C the wordmark, #C4C6CB the
         * stripe motif down both edges. No new assets, no Baja marks.
         */
        :root {
            --sae-navy: #004185;
            --sae-navy-deep: #003E7C;
            --sae-grey: #6a7078;
            --sae-rule: #C4C6CB;
            --sae-light: #f4f5f7;
            --sae-border: #d7dbe0;
            /* Same three as the insertion pages, so a message that means
               "wrong", "careful" or "done" looks the same on both surfaces. */
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
        .wrap { max-width: 720px; margin: 0 auto; padding: 24px 16px 48px; }
        .brand { padding: 4px 0 20px; border-bottom: 3px solid var(--sae-navy); margin-bottom: 20px; }
        .brand img { display: block; width: 100%; max-width: 291px; height: auto; }
        /* The stripe motif runs down both edges of the certificate itself. */
        .wrap::before {
            content: "";
            display: block;
            height: 6px;
            margin-bottom: 18px;
            background: repeating-linear-gradient(
                100deg,
                var(--sae-rule) 0 22px,
                transparent 22px 30px
            );
        }
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
        input[type=text], input[type=email], input[type=tel], select, textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--sae-border);
            border-radius: 4px;
            font-size: 16px;
            font-family: inherit;
            background: #fff;
            color: inherit;
        }
        textarea { min-height: 140px; resize: vertical; line-height: 1.45; }
        .field { margin-bottom: 16px; }
        /* The sentence under a label that says what the field is for. Printed
           before the input, not after: it is part of the question. */
        .dica { font-weight: normal; color: var(--sae-grey); font-size: 14px; display: block; margin: -2px 0 6px; }
        .contador { font-weight: normal; color: var(--sae-grey); font-size: 13px; float: right; }

        /*
         * A field that failed validation. Colour is not the only signal —
         * every one of these also carries a sentence saying what is wrong,
         * and the input gets aria-invalid, because a red border is nothing to
         * a screen reader and little to a colour-blind reader.
         */
        .campo-erro input, .campo-erro select, .campo-erro textarea { border-color: var(--erro); }
        .erro-msg { color: var(--erro); font-size: 14px; margin: 6px 0 0; }
        .alerta { border-left: 4px solid var(--sae-navy); padding: 12px 14px; margin-bottom: 16px; background: #fff; }
        .alerta.erro { border-color: var(--erro); color: var(--erro); }
        .alerta.aviso { border-color: var(--aviso); }
        .alerta.ok { border-color: var(--ok); color: var(--ok); }

        /*
         * The radio group choosing what is being reported, and the agreement
         * checkbox. Each option is a whole tappable block with its
         * explanation inside it, rather than a bare radio next to a phrase —
         * the explanations are what actually distinguish the three cases, and
         * a 20px hit area on a phone is not enough for a choice that decides
         * which form you get.
         */
        .escolhas { display: grid; gap: 10px; margin-bottom: 4px; }
        .escolha {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 4px 10px;
            align-items: start;
            border: 1px solid var(--sae-border);
            border-radius: 6px;
            padding: 12px 14px;
            font-weight: normal;
            margin: 0;
            cursor: pointer;
        }
        .escolha:hover { background: var(--sae-light); }
        .escolha input { margin: 3px 0 0; width: 18px; height: 18px; }
        .escolha strong { font-weight: bold; color: var(--sae-navy-deep); }
        .escolha span { grid-column: 2; color: var(--sae-grey); font-size: 14px; }
        .escolha:has(input:checked) { border-color: var(--sae-navy); box-shadow: inset 0 0 0 1px var(--sae-navy); }
        .concordo {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 10px;
            align-items: start;
            font-weight: normal;
        }
        .concordo input { margin: 3px 0 0; width: 18px; height: 18px; }

        /*
         * What the report form is about, restated above it. Not a decoration:
         * somebody arriving from a certificate needs to see which certificate
         * before they describe what is wrong with it.
         */
        .contexto { background: var(--sae-light); border-radius: 6px; padding: 14px 16px; margin-bottom: 20px; }
        .contexto dt { margin-top: 8px; }
        .contexto dt:first-child { margin-top: 0; }
        button {
            background: var(--sae-navy);
            color: #fff;
            border: 0;
            border-radius: 4px;
            padding: 12px 20px;
            font-size: 16px;
            cursor: pointer;
        }
        .btn {
            display: inline-block;
            background: var(--sae-navy);
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            padding: 10px 18px;
            margin-right: 8px;
        }
        .btn-secondary {
            background: #fff;
            color: var(--sae-navy);
            border: 1px solid var(--sae-navy);
        }
        dl { margin: 0; }
        dt { font-size: 13px; text-transform: uppercase; color: var(--sae-grey); margin-top: 12px; }
        dd { margin: 0; font-size: 17px; }
        .valid { color: #1a7f45; font-weight: bold; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="brand">
        <img src="/img/sae-brasil-wordmark.png" alt="SAE BRASIL — A casa do conhecimento da mobilidade brasileira" />
    </header>
        <?php
    }

    /**
     * The footer, and the one route out of these pages.
     *
     * This used to be a mailto: and nothing else, which put the whole burden
     * of the report on the person least equipped to carry it. What arrived
     * was "meu certificado está errado" with no event, no document, and no
     * indication of which of three quite different things had happened — so
     * the first reply was always a request for the same four facts, and the
     * thread took a week to reach the point a form reaches in one submission.
     *
     * The address is gone rather than kept as a fallback, deliberately. Two
     * channels means two queues, one of which has no protocol number, no
     * status, no record of who answered and no retention policy — and the
     * unstructured one is the one people pick, because it asks nothing of
     * them. The form is the contact route; /requerimento is written so that a
     * server with no working relay still accepts a report rather than sending
     * anybody back here looking for an address.
     */
    public static function printFooter(): void
    {
        ?>
    <p class="muted">
        Encontrou algum problema com um certificado?
        <a href="/requerimento">Abra um requerimento</a>.
        <?php if (Config::PRIVACY_NOTICE_URL !== ''): ?>
            Consulte também o
            <a href="<?= htmlspecialchars(Config::PRIVACY_NOTICE_URL, ENT_QUOTES, 'UTF-8') ?>">Aviso de Privacidade</a>.
        <?php endif; ?>
    </p>
</div>
</body>
</html>
        <?php
    }

    /**
     * Escape for HTML, as one short name.
     *
     * The same helper the insertion pages have, added here for the same
     * reason: /requerimento prints a form back to the person who filled it in,
     * every value in it came from a request, and htmlspecialchars with three
     * arguments repeated forty times is forty chances to leave one out.
     */
    public static function e(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The one failure page.
     *
     * Used for an unknown token and for a search that found nothing, with the
     * same wording in both cases. Telling them apart would answer "did this
     * person compete?" to anyone who asked.
     */
    public static function printNotFound(): void
    {
        self::printHeader('Certificado não encontrado - SAE BRASIL');
        ?>
    <div class="card">
        <h1>Certificado não encontrado</h1>
        <p><?= htmlspecialchars(Config::FAILURE_MESSAGE, ENT_QUOTES, 'UTF-8') ?></p>
        <p><a class="btn" href="/buscar">Buscar certificados</a></p>
    </div>
        <?php
        self::printFooter();
    }
}
