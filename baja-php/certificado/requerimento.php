<?php
/**
 * /requerimento — tell us something about a certificate is wrong.
 *
 * The footer of every certificate page used to be a mailto: and nothing else.
 * What arrived through it was "meu certificado está errado", with no event, no
 * document and no indication of which of three quite different problems had
 * happened, so the first reply was always a request for the same four facts.
 * This asks for them once.
 *
 * GET renders the form; POST validates, saves and answers in the same
 * response, without a redirect — the same choice /buscar makes, for a
 * stronger reason. The form carries a CPF and a full legal name, so nothing
 * about a submission may end up anywhere a URL is recorded.
 *
 * Two entry points, and which one somebody came through decides what the form
 * asks:
 *
 *   /requerimento?caso=incorreto&t={token}   from a result on /buscar
 *   /requerimento?caso=indevido&t={token}    likewise
 *   /requerimento?caso=ausente               from the search form itself
 *
 * The two that name a certificate cannot be filled in without one, on purpose
 * — see Baja\Certificado\Requerimento\Caso. Reaching this page with no parameters
 * at all is fine and lands on an unanswered radio group.
 *
 * No CSRF token, and not by oversight. This vhost has no session of any kind
 * — it runs with SKIP_AUTH so that a certificate lookup does not depend on the
 * forum — so Insercao\Csrf, which derives its token from the phpBB session id,
 * has nothing to derive from here. What a forged submission would achieve is
 * also different: it creates a report, which changes no certificate and which
 * a person then reads. The Turnstile token is the control that actually binds
 * a submission to somebody having loaded this page, and it is bound to the
 * origin, which a CSRF token is not.
 *
 * Nothing in this file logs any field of the form.
 */

use Baja\Certificado\Certificado;
use Baja\Certificado\Config;
use Baja\Certificado\Http;
use Baja\Certificado\Insercao\Texto;
use Baja\Certificado\Requerimento\Aviso;
use Baja\Certificado\Requerimento\Caso;
use Baja\Certificado\Requerimento\Formulario;
use Baja\Certificado\Requerimento\Gravador;
use Baja\Certificado\Requerimento\Limite;
use Baja\Certificado\Template;
use Baja\Certificado\Token;
use Baja\Model\EventoQuery;
use Baja\Util\Turnstile;

Http::sendPrivateHeaders();

$e = static fn (string $v): string => Template::e($v);

/**
 * The events somebody can pick from, newest first.
 *
 * `titulo` rather than `nome` because it carries the year — "Baja SAE BRASIL
 * - Etapa Sul 2025" against "22ª Competição Baja SAE BRASIL - Etapa Sul" —
 * and the year is what a person filling this in a decade later actually
 * remembers. Stored names hold HTML entities as literal text, hence the
 * decode; the same one Insercao\Eventos does.
 *
 * @var array<string, string> $eventos
 */
$eventos = [];
foreach (EventoQuery::create()->orderByAno('desc')->orderByEventoId('desc')->find() as $evento) {
    $eventos[(string) $evento->getEventoId()] = html_entity_decode(
        (string) ($evento->getTitulo() ?: $evento->getNome()),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$formulario    = null;
$protocolo     = null;
$avisoErro     = '';
$espera        = null;
$reciboEnviado = false;

// GET prefill. Read from $_GET, not $_REQUEST, so nothing about a POST leaks
// into what the blank form shows.
$casoInicial = Texto::escalar($_GET['caso'] ?? '');
$tokenGet    = Texto::escalar($_GET['t'] ?? '');

if (!Caso::valido($casoInicial)) {
    $casoInicial = '';
}

/*
 * The certificate this is about, when the page was reached from a result.
 *
 * Resolved on GET as well as POST, because the person has to see which
 * certificate they are reporting before they describe what is wrong with it.
 * A token that does not resolve is treated as no token: the case that needs
 * one will say so when the form is submitted.
 */
$certificadoGet = Token::isWellFormed($tokenGet) ? Certificado::fromToken($tokenGet) : null;

if ($isPost) {
    /*
     * The order matters. The cheap refusals come first so that a flood costs
     * a Redis read rather than a database write and two SMTP conversations,
     * and the limiter is consulted before the captcha so that a caller who is
     * already over the limit does not also spend a Turnstile verification.
     */
    $origem = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

    // Cleaned exactly as Formulario will clean it, or a trailing space buys a
    // fresh counter — the check and the count have to agree on what one
    // address is.
    $emailBruto = Texto::limpar(Texto::escalar($_POST['email'] ?? ''));
    $espera     = Limite::esperaSegundos($origem, $emailBruto);

    /*
     * The trap field. Anything in it means the submission was not typed by
     * somebody looking at the page, and the answer is the ordinary success
     * page with nothing written down — a bot told it failed tries again.
     */
    $armadilha = Texto::escalar($_POST[Formulario::CAMPO_ARMADILHA] ?? '') !== '';

    if ($espera !== null) {
        // Falls through to the "too many" card below.
    } elseif ($armadilha) {
        $protocolo = '';
    } elseif (!Turnstile::verificar(Texto::escalar($_POST[Turnstile::CAMPO] ?? ''), $origem)) {
        $avisoErro = 'Não foi possível confirmar que você não é um robô. Recarregue a página e tente de novo.';
    } else {
        $formulario = new Formulario($_POST, $eventos);

        if ($formulario->valido()) {
            $requerimento = Gravador::gravar($formulario);

            /*
             * The row is written before anything is sent, and the protocol is
             * shown whether or not either message goes out.
             *
             * This is why the report is stored rather than mailed. A server
             * with no relay configured still takes the report: it lands in the
             * queue with avisado_em null, the staff page says so in as many
             * words, and the person is told plainly that no confirmation is
             * coming. Refusing the submission instead would turn a mail
             * misconfiguration into "this person cannot report that their own
             * certificate is wrong" — and the form is now the only channel
             * there is.
             */
            $protocolo = $requerimento->getRequerimentoId();

            // Always, including for a report this system decides not to alert
            // anybody about. Being the fourth person to report one typo is not
            // a reason to hear nothing back.
            $reciboEnviado = Aviso::confirmarAoParticipante($requerimento);

            if (Gravador::jaAvisadoRecentemente($requerimento)) {
                // Somebody already reported this certificate today and nobody
                // has closed it yet. The row still exists; only the repeat
                // alert is suppressed.
                Gravador::marcarAvisado($requerimento);
            } elseif (Aviso::alertarEquipe($requerimento, Aviso::captchaAtivo())) {
                Gravador::marcarAvisado($requerimento);
            } else {
                error_log('Certificado\Requerimento: saved ' . $requerimento->getRequerimentoId() . ' but the staff alert failed.');
            }

            Limite::registrar($origem, $formulario->email());
        }
    }
}

/*
 * What the form should show. After a failed POST it is what was submitted;
 * otherwise it is the GET prefill.
 */
$casoAtual   = $formulario !== null ? $formulario->caso() : $casoInicial;
$certificado = $formulario !== null ? $formulario->certificado() : $certificadoGet;
$tokenAtual  = $formulario !== null ? $formulario->valor('token') : ($certificadoGet !== null ? $tokenGet : '');

Template::printHeader('Abrir um requerimento - Certificados SAE BRASIL');
?>

<?php if ($protocolo !== null): ?>
    <div class="card">
        <h1>Requerimento recebido</h1>
        <?php if ($protocolo !== ''): ?>
            <p>
                Guarde o número de protocolo:
                <strong><?= $e($protocolo) ?></strong>
            </p>
            <?php if ($reciboEnviado): ?>
                <p>
                    Enviamos uma confirmação para o e-mail informado. A equipe responsável
                    pelos certificados vai analisar o pedido e responder por lá.
                </p>
            <?php else: ?>
                <?php /*
                 * The report is saved; only the confirmation failed. Saying so
                 * matters more than it looks: without the email, the protocol
                 * printed above is the only copy the person has, and somebody
                 * who was promised a message that never comes assumes the
                 * whole thing failed and submits again.
                 */ ?>
                <p class="alerta aviso">
                    Não conseguimos enviar a confirmação por e-mail, então
                    <strong>anote o número de protocolo acima</strong>. O requerimento
                    foi registrado e a equipe responsável pelos certificados vai
                    analisá-lo mesmo assim.
                </p>
            <?php endif; ?>
        <?php else: ?>
            <p>Recebemos o seu envio.</p>
        <?php endif; ?>
        <p class="muted">
            Nada foi alterado em nenhum certificado. Um requerimento é um pedido de
            análise, e certificados já emitidos continuam válidos e verificáveis
            enquanto ele corre.
        </p>
        <p><a class="btn" href="/buscar">Voltar para a busca</a></p>
    </div>
<?php else: ?>

    <div class="card">
        <h1>Abrir um requerimento</h1>
        <p>
            Use este formulário para avisar a SAE BRASIL sobre um certificado com
            dados errados, um certificado que está faltando, ou um certificado que
            não deveria existir.
        </p>
        <p class="muted">
            Não é um canal de exclusão de dados. Um certificado corretamente emitido
            continua verificável mesmo que você prefira que não estivesse aqui, já que
            outras pessoas dependem dessa verificação, e a lei prevê a guarda desse
            registro. O que se corrige aqui é aquilo que está <em>errado</em>.
        </p>
    </div>

    <?php if ($espera !== null): ?>
        <div class="card">
            <h2>Muitos envios</h2>
            <p class="alerta aviso">
                Já recebemos vários requerimentos deste dispositivo ou deste e-mail
                há pouco. Aguarde um pouco e tente de novo.
            </p>
            <p class="muted">
                Se você já enviou um requerimento, ele está na fila e não é preciso
                reenviar.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($avisoErro !== ''): ?>
        <div class="card">
            <p class="alerta erro"><?= $e($avisoErro) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($formulario !== null && !$formulario->valido()): ?>
        <div class="card">
            <p class="alerta erro">
                Faltou alguma coisa. Veja os campos marcados abaixo, o resto do que
                você escreveu foi mantido.
            </p>
        </div>
    <?php endif; ?>

    <div class="card">
        <?php if ($certificado !== null): ?>
            <?php /* Which certificate, in the words the person just read on
                     /buscar. Without this the form is an abstraction: they are
                     describing an error in something the page is not showing. */ ?>
            <dl class="contexto" data-so-com-token>
                <dt>Certificado</dt>
                <dd><?= $e($certificado->getNome()) ?></dd>
                <dt>Evento</dt>
                <dd><?= $e($certificado->getEventoNome()) ?></dd>
                <?php if ($certificado->getFuncaoLabel() !== ''): ?>
                    <dt>Participação</dt>
                    <dd><?= $e($certificado->getFuncaoLabel()) ?></dd>
                <?php endif; ?>
            </dl>
        <?php endif; ?>

        <form method="post" action="/requerimento" autocomplete="off">
            <input type="hidden" name="token" value="<?= $e($tokenAtual) ?>" />

            <?php /*
             * The trap. Off-screen rather than display:none — some bots skip
             * anything hidden — and marked aria-hidden with tabindex -1 so
             * that nobody using a keyboard or a screen reader ever lands in
             * it. autocomplete="off" keeps a browser from helpfully filling
             * it in and failing the submission for a real person.
             */ ?>
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">
                <label for="<?= Formulario::CAMPO_ARMADILHA ?>">Não preencha este campo</label>
                <input type="text" id="<?= Formulario::CAMPO_ARMADILHA ?>"
                       name="<?= Formulario::CAMPO_ARMADILHA ?>" tabindex="-1" autocomplete="off" value="" />
            </div>

            <fieldset class="field <?= $formulario !== null && $formulario->erro('caso') !== '' ? 'campo-erro' : '' ?>"
                      style="border:0;padding:0;margin:0 0 20px">
                <legend style="font-weight:bold;padding:0;margin-bottom:8px">O que está acontecendo?</legend>
                <div class="escolhas">
                    <?php foreach (Caso::TODOS as $caso): ?>
                        <label class="escolha">
                            <input type="radio" name="caso" value="<?= $e($caso) ?>"
                                   <?= $casoAtual === $caso ? 'checked' : '' ?> />
                            <strong><?= $e(Caso::rotulo($caso)) ?></strong>
                            <span><?= $e(Caso::explicacoes()[$caso]) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($formulario !== null && $formulario->erro('caso') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('caso')) ?></p>
                <?php endif; ?>
                <?php if ($certificado === null): ?>
                    <p class="muted" style="margin-top:8px">
                        As duas primeiras opções são sobre um certificado que existe, e
                        precisam que você o encontre antes. <a href="/buscar">Faça a busca</a>
                        e use um dos links de correção que aparecem no certificado.
                    </p>
                <?php endif; ?>
                <?php if ($formulario !== null && $formulario->erro('token') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('token')) ?></p>
                <?php endif; ?>
            </fieldset>

            <?php /*
             * The event, asked when nothing else can answer the question.
             * A report about a certificate already names its event — that is
             * why the two cases carrying a token do not ask — but a report
             * that one is missing cannot, and there "não está na lista" is not
             * a courtesy: the participants most likely to be missing a
             * certificate are exactly the ones whose event predates this
             * system, so a bare dropdown of what the database happens to hold
             * would turn them away.
             *
             * Rendered on every case, and hidden by the script at the bottom
             * for the ones that do not need it. The other order — render only
             * when the case needs it — needs a round trip to the server the
             * moment somebody changes their mind about which case they are
             * reporting, and leaves anyone with no JavaScript unable to
             * answer a required field. Visible is the honest default.
             */ ?>
            <div class="field <?= $formulario !== null && $formulario->erro('evento') !== '' ? 'campo-erro' : '' ?>"
                 data-so-sem-token>
                <label for="evento">De qual evento se trata?</label>
                <span class="dica">
                    Se o evento for antigo e não estiver na lista, escolha
                    "Outro / não está na lista" e escreva o nome e o ano.
                </span>
                <select id="evento" name="evento"
                        <?= $formulario !== null && $formulario->erro('evento') !== '' ? 'aria-invalid="true"' : '' ?>>
                    <option value="">selecione</option>
                    <?php foreach ($eventos as $codigo => $nomeEvento): ?>
                        <option value="<?= $e($codigo) ?>"
                                <?= ($formulario !== null ? $formulario->valor('evento') : '') === $codigo ? 'selected' : '' ?>>
                            <?= $e($nomeEvento) ?>
                        </option>
                    <?php endforeach; ?>
                    <option value="<?= Formulario::EVENTO_OUTRO ?>"
                            <?= ($formulario !== null ? $formulario->valor('evento') : '') === Formulario::EVENTO_OUTRO ? 'selected' : '' ?>>
                        Outro / não está na lista
                    </option>
                </select>
                <div style="margin-top:10px" data-so-evento-outro>
                    <label for="evento_texto">Qual evento, e em que ano?</label>
                    <input type="text" id="evento_texto" name="evento_texto" maxlength="160"
                           placeholder="Ex.: Baja SAE BRASIL Etapa Sudeste, 2009"
                           value="<?= $e($formulario !== null ? $formulario->valor('evento_texto') : '') ?>" />
                </div>
                <?php if ($formulario !== null && $formulario->erro('evento') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('evento')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field <?= $formulario !== null && $formulario->erro('nome') !== '' ? 'campo-erro' : '' ?>">
                <label for="nome">Nome completo</label>
                <span class="dica">
                    O nome inteiro, como está no seu documento, sem abreviações e sem
                    deixar nenhum sobrenome de fora. Se o problema for justamente o nome
                    do certificado, escreva aqui o nome <strong>correto</strong>.
                </span>
                <input type="text" id="nome" name="nome" maxlength="300" required
                       <?= $formulario !== null && $formulario->erro('nome') !== '' ? 'aria-invalid="true"' : '' ?>
                       value="<?= $e($formulario !== null ? $formulario->valor('nome') : '') ?>" />
                <?php if ($formulario !== null && $formulario->erro('nome') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('nome')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field <?= $formulario !== null && $formulario->erro('documento') !== '' ? 'campo-erro' : '' ?>">
                <label for="documento">CPF ou passaporte</label>
                <span class="dica">É o que liga você aos registros do evento.</span>
                <?php /* type="text", never type="number" — the same reason
                         /buscar gives: a number input eats the leading zero of
                         a CPF and refuses the punctuation people type. */ ?>
                <input type="text" id="documento" name="documento" inputmode="text" maxlength="32" required
                       <?= $formulario !== null && $formulario->erro('documento') !== '' ? 'aria-invalid="true"' : '' ?>
                       value="<?= $e($formulario !== null ? $formulario->valor('documento') : '') ?>" />
                <?php if ($formulario !== null && $formulario->erro('documento') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('documento')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field <?= $formulario !== null && $formulario->erro('email') !== '' ? 'campo-erro' : '' ?>">
                <label for="email">E-mail para retorno</label>
                <span class="dica">A resposta vem para cá. Confira antes de enviar.</span>
                <input type="email" id="email" name="email" maxlength="254" required
                       <?= $formulario !== null && $formulario->erro('email') !== '' ? 'aria-invalid="true"' : '' ?>
                       value="<?= $e($formulario !== null ? $formulario->valor('email') : '') ?>" />
                <?php if ($formulario !== null && $formulario->erro('email') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('email')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field <?= $formulario !== null && $formulario->erro('telefone') !== '' ? 'campo-erro' : '' ?>">
                <label for="telefone">Telefone <span class="dica" style="display:inline">(opcional)</span></label>
                <input type="tel" id="telefone" name="telefone" maxlength="40"
                       <?= $formulario !== null && $formulario->erro('telefone') !== '' ? 'aria-invalid="true"' : '' ?>
                       value="<?= $e($formulario !== null ? $formulario->valor('telefone') : '') ?>" />
                <?php if ($formulario !== null && $formulario->erro('telefone') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('telefone')) ?></p>
                <?php endif; ?>
            </div>

            <div class="field <?= $formulario !== null && $formulario->erro('descricao') !== '' ? 'campo-erro' : '' ?>">
                <label for="descricao">O que está errado, e qual seria o correto?</label>
                <?php /*
                 * The one field with no shape, so it is the one that needs the
                 * warning. There is no way to enforce it — a free-text box
                 * takes whatever is typed into it — so the request is specific
                 * about what not to send rather than a general "não inclua
                 * dados sensíveis", which nobody can act on. The staff page
                 * repeats the warning to whoever reads the answer.
                 */ ?>
                <span class="dica">
                    Escreva o necessário para a equipe entender e conferir o registro.
                    <strong>Não inclua aqui dados pessoais sensíveis.</strong> Se algum
                    deles for indispensável, diga apenas que existe e a equipe combinará
                    com você como enviá-lo.
                </span>
                <textarea id="descricao" name="descricao" maxlength="2000" required
                          <?= $formulario !== null && $formulario->erro('descricao') !== '' ? 'aria-invalid="true"' : '' ?>
                          placeholder="Ex.: meu nome está registrado como &quot;Joao Silva&quot; e o correto é &quot;João Alves da Silva&quot;."><?= $e($formulario !== null ? $formulario->valor('descricao') : '') ?></textarea>
                <?php if ($formulario !== null && $formulario->erro('descricao') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('descricao')) ?></p>
                <?php endif; ?>
            </div>

            <?php /*
             * The transparency notice, LGPD Art. 9. It is above the checkbox
             * and not behind a link, because the checkbox asserts that it was
             * read.
             *
             * "Estou ciente", not "concordo" — the agreement is not the legal
             * basis. Handling a report about somebody's own certificate rests
             * on Art. 7 V (execution of an agreement they are party to) and
             * Art. 16 II (retention of a record for the exercise of rights);
             * consent would be the one basis they could withdraw tomorrow,
             * obliging deletion of the very report they asked us to act on.
             */ ?>
            <div class="field <?= $formulario !== null && $formulario->erro('ciente') !== '' ? 'campo-erro' : '' ?>">
                <div class="contexto">
                    <p style="margin-bottom:8px"><strong>Como os seus dados serão tratados</strong></p>
                    <p class="muted" style="margin-bottom:8px">
                        Os dados acima ficam registrados em um sistema da SAE BRASIL e são
                        acessíveis à equipe responsável por certificados, que também recebe
                        um aviso por e-mail de que existe um requerimento novo. Esse aviso
                        não contém os seus dados, apenas o link para esta análise.
                        São usados só para analisar e responder a este pedido.
                    </p>
                    <?php /*
                     * Where somebody exercises their rights over what they are
                     * about to type. It has to be a channel that exists: this
                     * form is now the only contact route on these pages, and
                     * the confirmation it sends is the reply-able end of it.
                     */ ?>
                    <p class="muted" style="margin-bottom:0">
                        Você recebe uma confirmação por e-mail com um número de protocolo.
                        Para pedir acesso, correção ou exclusão dos seus dados de contato,
                        responda àquela confirmação citando o protocolo.
                        Certificados válidos já emitidos permanecem verificáveis.
                        <?php if (Config::PRIVACY_NOTICE_URL !== ''): ?>
                            Consulte o
                            <a href="<?= $e(Config::PRIVACY_NOTICE_URL) ?>">Aviso de Privacidade</a>.
                        <?php endif; ?>
                    </p>
                </div>
                <label class="concordo">
                    <input type="checkbox" name="ciente" value="1"
                           <?= ($formulario !== null ? $formulario->valor('ciente') : '') === '1' ? 'checked' : '' ?> />
                    <span>
                        Estou ciente de que estes dados serão registrados e analisados pela
                        equipe da SAE BRASIL responsável por certificados, e de que os dados
                        que informei são meus ou fui autorizado a informá-los.
                    </span>
                </label>
                <?php if ($formulario !== null && $formulario->erro('ciente') !== ''): ?>
                    <p class="erro-msg"><?= $e($formulario->erro('ciente')) ?></p>
                <?php endif; ?>
            </div>

            <?php if (Turnstile::habilitado()): ?>
                <div class="field">
                    <?php /*
                     * The only third-party resource on any of these pages.
                     * Turnstile rather than reCAPTCHA because it sets no
                     * cookie — which is what keeps true the claim in
                     * Certificado\Template that these pages need no consent
                     * banner. See Baja\Util\Turnstile.
                     */ ?>
                    <div class="cf-turnstile" data-sitekey="<?= $e(Turnstile::siteKey()) ?>" data-language="pt-br"></div>
                    <script src="<?= $e(Turnstile::SCRIPT_URL) ?>" async defer></script>
                    <?php /*
                     * The one dead end this page still has. Turnstile cannot
                     * produce a token without JavaScript, so the submission
                     * will be refused, and there is no second channel to send
                     * anybody to any more. So the message is an instruction
                     * that can actually be followed rather than an apology.
                     */ ?>
                    <noscript>
                        <p class="alerta erro">
                            A verificação anti-robô precisa de JavaScript. Ative o JavaScript
                            para este site e recarregue a página, porque sem ele o envio não
                            é aceito.
                        </p>
                    </noscript>
                </div>
            <?php endif; ?>

            <button type="submit">Enviar requerimento</button>
        </form>

        <?php /*
         * Progressive enhancement, and nothing else depends on it.
         *
         * Everything this hides is visible without it and every field it
         * hides is still validated on the server, so with JavaScript off the
         * form is longer and asks one question it did not need to — which is
         * the failure the other direction does not survive, where a required
         * field is hidden and the form can never be completed.
         *
         * Inline rather than a file under /js/. It is fifteen lines that
         * belong to this page, and the alternative is a request whose only
         * job is to arrive before somebody reads the second question.
         */ ?>
        <script>
            (function () {
                var form = document.querySelector('form[action="/requerimento"]');
                if (!form) { return; }

                var AUSENTE = <?= json_encode(Caso::AUSENTE) ?>;
                var OUTRO   = <?= json_encode(Formulario::EVENTO_OUTRO) ?>;

                function mostrar(seletor, visivel) {
                    form.parentNode.querySelectorAll(seletor).forEach(function (el) {
                        el.hidden = !visivel;
                    });
                }

                function sincronizar() {
                    var escolhido = form.querySelector('input[name="caso"]:checked');
                    var caso      = escolhido ? escolhido.value : '';
                    var evento    = form.querySelector('#evento');

                    // Nothing chosen yet shows everything, so the page never
                    // starts by hiding a question somebody has not read.
                    mostrar('[data-so-com-token]', caso === '' || caso !== AUSENTE);
                    mostrar('[data-so-sem-token]', caso === '' || caso === AUSENTE);
                    mostrar('[data-so-evento-outro]', !evento || evento.value === '' || evento.value === OUTRO);
                }

                form.addEventListener('change', function (ev) {
                    if (ev.target.name === 'caso' || ev.target.id === 'evento') { sincronizar(); }
                });

                sincronizar();
            })();
        </script>
    </div>
<?php endif; ?>
<?php
/*
 * No submitted value is printed outside the form above, and none is logged.
 */
Template::printFooter();
