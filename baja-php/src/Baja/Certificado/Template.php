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
    <!-- Belt and braces with the X-Robots-Tag header sent by Http. -->
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root {
            --sae-navy: #0b2b5b;
            --sae-grey: #5b6770;
            --sae-light: #f4f5f7;
            --sae-border: #d7dbe0;
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
        .card {
            background: #fff;
            border: 1px solid var(--sae-border);
            border-radius: 6px;
            padding: 24px;
            margin-bottom: 16px;
        }
        h1 { font-size: 22px; margin: 0 0 4px; color: var(--sae-navy); }
        h2 { font-size: 18px; margin: 0 0 12px; color: var(--sae-navy); }
        p { margin: 0 0 12px; }
        .muted { color: var(--sae-grey); font-size: 14px; }
        label { display: block; font-weight: bold; margin-bottom: 4px; }
        input[type=text] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--sae-border);
            border-radius: 4px;
            font-size: 16px;
        }
        .field { margin-bottom: 16px; }
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
        <?php
    }

    public static function printFooter(): void
    {
        ?>
    <p class="muted">
        Se algum dado exibido estiver incorreto, escreva para
        <a href="mailto:<?= Config::CONTACT_EMAIL ?>"><?= Config::CONTACT_EMAIL ?></a>.
    </p>
</div>
</body>
</html>
        <?php
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
