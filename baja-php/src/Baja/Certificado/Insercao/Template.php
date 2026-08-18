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
    <div class="voltar"><a href="index.php">&larr; Voltar para a entrada de dados</a></div>
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
