<?php
declare(strict_types=1);

namespace Baja\Auth;

/**
 * The page for somebody who is logged into the forum and has no account here.
 *
 * These are two separate account systems. phpBB says who you are; the `user`
 * table in baja_resultados says what you may do, and a person can perfectly
 * well have the first without the second — every new forum member does.
 *
 * Until now that state redirected to the login page with no message, where
 * logging in succeeded, redirected back, and bounced again. From the outside
 * it is indistinguishable from a broken login, so the person retries, then
 * tries another browser, then reports that the site is down. It is the first
 * thing an administrator hits on a new account, and the certificate insertion
 * pages are exactly where new administrators are sent.
 *
 * Deliberately self-contained. This fires on any vhost, including ones whose
 * page templates want a current user — which is the thing that does not
 * exist here.
 */
final class NaoProvisionado
{
    public static function render(string $username): void
    {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, private');

        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $contatos = array_values(array_filter([
            (string) getenv('SYSADMIN_1_EMAIL'),
            (string) getenv('SYSADMIN_2_EMAIL'),
        ]));

        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow">
    <title>Conta sem acesso</title>
    <style>
        body {
            margin: 0; padding: 0; background: #f4f5f7; color: #1c2226;
            font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 1.5;
        }
        .wrap { max-width: 560px; margin: 48px auto; padding: 0 16px; }
        .card { background: #fff; border: 1px solid #d7dbe0; border-radius: 6px; padding: 24px; }
        h1 { font-size: 22px; margin: 0 0 12px; color: #003E7C; }
        p { margin: 0 0 12px; }
        .muted { color: #6a7078; font-size: 14px; }
        code { font-family: monospace; background: #f4f5f7; padding: 1px 4px; border-radius: 3px; }
        a { color: #004185; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Sua conta ainda não tem acesso a este sistema</h1>
        <p>
            Você está conectado no fórum como
            <code><?= $e($username) ?></code>, e essa parte funcionou.
        </p>
        <p>
            O que falta é o acesso a este sistema, que é concedido separadamente:
            são dois cadastros diferentes, e o seu ainda não foi criado aqui.
            Não adianta tentar entrar de novo — o login vai continuar dando certo
            e trazendo você para esta página.
        </p>
        <?php if ($contatos !== []): ?>
            <p>
                Peça a liberação para
                <?php foreach ($contatos as $i => $contato): ?>
                    <?= $i > 0 ? ' ou ' : '' ?><a href="mailto:<?= $e($contato) ?>"><?= $e($contato) ?></a><?php endforeach; ?>,
                informando o nome de usuário acima e o que você precisa fazer.
            </p>
        <?php else: ?>
            <p>Peça a liberação a um administrador do sistema, informando o nome de usuário acima.</p>
        <?php endif; ?>
        <p class="muted">
            Se você não deveria ter acesso a este sistema, não há nada errado:
            esta página é só o fim do caminho.
        </p>
    </div>
</div>
</body>
</html>
        <?php
        exit();
    }
}
