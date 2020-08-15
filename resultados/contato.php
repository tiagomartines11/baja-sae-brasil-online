<?php

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Site\Template;
use PHPMailer\PHPMailer\PHPMailer;

if (!isset($_SESSION)) session_start();

if (@$_REQUEST['act'] == 'submit') {

$post_data = http_build_query(
    array(
        'secret' => $_recaptchaKey,
        'response' => $_POST['g-recaptcha-response'],
        'remoteip' => $_SERVER['REMOTE_ADDR']
    )
);
$opts = array('http' =>
    array(
        'method'  => 'POST',
        'header'  => 'Content-type: application/x-www-form-urlencoded',
        'content' => $post_data
    )
);
$context  = stream_context_create($opts);
$response = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
$result = json_decode($response);
if (!$result->success) {
 $msg = 'Captcha incorreto!';
}
else if (!@$_POST['nome'] || !@$_POST['email'] || !@$_POST['equipe'] || !@$_POST['msg']) {
        $msg = 'Por favor preencha todos os dados antes de enviar sua mensagem!';
    } else {
        $mail = new PHPMailer;
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();                                      // Set mailer to use SMTP
        $mail->Host = 'bajasaebrasil.online';               // Specify main and backup SMTP servers
        $mail->SMTPAuth = true;                               // Enable SMTP authentication
        $mail->Username = 'no-reply@bajasaebrasil.online';    // SMTP username
        $mail->Password = $_smtpPassword;                 // SMTP password
        $mail->SMTPSecure = 'tls';                            // Enable TLS encryption, `ssl` also accepted
        $mail->Port = 587;                                    // TCP port to connect to

        $mail->setFrom('no-reply@bajasaebrasil.online', 'Baja SAE Brasil Online');
        $mail->addAddress('contato@bajasaebrasil.online');
        $mail->addBCC($_tEmail);
        $mail->addBCC($_fEmail);
        $mail->addReplyTo($_POST['email'], $_POST['nome']);

        $mail->Subject = 'Mensagem via Baja SAE Brasil Online';
        $mail->Body    = '
Nome: '.$_POST['nome'].'
Equipe: '.$_POST['equipe'].'

'.strip_tags($_POST['msg']).'';

        if ($mail->send()) {
            $msg = "Mensagem enviada com sucesso! Entraremos em contato o mais breve possível.";
            unset($_POST);
        } else
            $msg = "Erro ao enviar a mensagem. Tente mais tarde ou nos procure pessoalmente.";
    }
}

Template::printHeader("Contato");
?>
<table width="100%">
<tbody>
<tr>
    <td style="vertical-align: middle; padding: 10px;">
        <span style="font-size: 22px;">Contato</span><br /><br />
        Utilize esse formulário para informar algum problema à organização.<br />
        Somente problemas referentes a este site e/ou informações contidas nele devem ser comunicados usando esse formulário.
    </td>
</tr>
<tr>
    <td style="padding: 10px;">
        <form action="contato.php?act=submit" method="POST">
        <span style="color: red"><?php echo (isset($msg) ? $msg . '<br /><br />' : ''); ?></span>
        <label for="nome">Seu Nome:</label><br />
        <input type="text" name="nome" id="nome" style="width: 250px;" value="<?php echo @$_POST['nome']; ?>" />
        <br /><br />
        <label for="equipe">Sua Equipe:</label><br />
        <select name="equipe" id="equipe" style="width: 250px;">
            <option value=""></option>
            <option value="Sem Equipe"> Sem Equipe</option>
            <option value="Organização / SAE / Juiz / Comissário">Organização / SAE / Juiz / Comissário</option>
            <?php foreach (EquipeQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->orderByEquipeId()->find() as $equipe) {
                echo '<option value="'.$equipe->getEquipeId().'" '.($equipe->getEquipeId() == @$_POST['equipe'] ? 'selected' : '').'>#'.$equipe->getEquipeId().' - '.$equipe->getEscola().' - '.$equipe->getEquipe().'</option>';
            } ?>
        </select>
        <br /><br />
        <label for="email">Seu Email:</label><br />
        <input type="email" name="email" id="email" style="width: 250px;" value="<?php echo @$_POST['email']; ?>" />
        <br /><br />
        <label for="msg">Sua mensagem:</label><br />
        <textarea name="msg" id="msg" style="width: 250px; height: 200px;"><?php echo @$_POST['msg']; ?></textarea>
        <br />
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>        
        <div style="text-align: center;"><div style="display: inline-block;" class="g-recaptcha" data-sitekey="6LfWOL8ZAAAAAGefI3tPR_wrqnmQ5goRuJQTy9bj"></div></div>
        <br /><br />
        <input type="submit" value="Enviar">
        </form>
    </td>
</tr>
</tbody>
</table>

<?php
Template::printFooter();
