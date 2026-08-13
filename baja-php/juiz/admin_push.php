<?php
namespace Baja\Juiz;

use Baja\Model\EventoQuery;
use Baja\Model\EquipeQuery;
use Baja\Site\OneSignalClient;
use Baja\Session;

Session::permissionCheck("admin");

$evento = EventoQuery::getCurrentEvent()->getEventoId();
$equipes = EquipeQuery::create()->filterByEventoId($evento)->orderByEquipeId()->find();

// Handle notification sending
if (@$_REQUEST['act'] == 'send') {
    $title = $_POST['title'] ?? '';
    $message = $_POST['message'] ?? '';
    $url = $_POST['url'] ?? '';
    $sendAll = !empty($_POST['send_all']);
    $selectedTeams = $_POST['teams'] ?? [];

    if (empty($title) || empty($message)) {
        $error = "Título e mensagem são obrigatórios.";
    } else {
        if ($sendAll) {
            // Send to All Subscribers segment instead of filtering by psa tag
            $response = sendPushToAll($title, $message, $url);
            if ($response === false) {
                $error = "Erro ao enviar notificação. Verifique se existem inscritos.";
            } else {
                $success = "Notificação enviada com sucesso!";
            }
        } elseif (!empty($selectedTeams)) {
            // Send to each selected team
            $results = [];
            foreach ($selectedTeams as $teamId) {
                $response = OneSignalClient::sendMessage($title, $message, $url, $teamId);
                $results[] = "Equipe #$teamId";
            }
            $success = "Notificações enviadas para: " . implode(", ", $results);
        } else {
            $error = "Selecione pelo menos uma equipe ou escolha 'Enviar para todos'.";
        }
    }
}

// Function to send to all subscribers (uses tag instead of segment)
function sendPushToAll($title, $message, $url = "") {
    global $_oneSignalAuth;
    $evento = $_SERVER['REDIRECT_EVENT'] ?? '26BR';

    $content = array("en" => $message);
    $headings = array("en" => $title);

    $fields = array(
        'app_id' => "2d1b50b2-362f-49c1-a854-3c8c7e0db587",
        'contents' => $content,
        'headings' => $headings,
        // Target web push specifically and all device types
        'target_channel' => 'push',
        'include_external_user_ids' => array('all'),
        // Also filter by the psa tag for this event
        'filters' => array(
            array("field" => "tag", "key" => "{$evento}_psa", "relation" => "=", "value" => "1")
        )
    );

    if ($url) {
        $fields['url'] = "/" . $_SERVER['REDIRECT_EVENT'] . "/" . $url;
    }

    $jsonFields = json_encode($fields);
    error_log("sendPushToAll Request: " . $jsonFields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.onesignal.com/notifications");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Basic ' . $_oneSignalAuth
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, FALSE);
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("sendPushToAll Response: HTTP $httpCode - $response");

    if ($httpCode != 200) {
        error_log("OneSignal API Error in sendPushToAll: HTTP $httpCode - Response: $response");
        return false;
    }

    return $response;
}

Template::printHeader("Admin");

?>

<div style="max-width: 800px; margin: 0 auto;">

<table class="tablesorter">
    <thead>
        <tr style="height: 50px;">
            <th colspan="2" class="sorter-false">
                <span style="float:left;"><a href="admin.php" style="color: white; font-size: 12px;">&nbsp;Voltar</a></span>
                <span style="font-size: 28px;">Enviar Notificações (<?= $evento ?>)</span>
            </th>
        </tr>
    </thead>
    <tbody>
<?php if (isset($success)) { ?>
        <tr><td colspan="2" style="background: #d4edda; color: #155724; padding: 15px;"><?= $success ?></td></tr>
<?php } ?>

<?php if (isset($error)) { ?>
        <tr><td colspan="2" style="background: #f8d7da; color: #721c24; padding: 15px;"><?= $error ?></td></tr>
<?php } ?>
    </tbody>
</table>

<form action="admin_push.php?act=send" method="POST">
    <table class="tablesorter">
        <thead>
            <tr>
                <th colspan="2">Nova Notificação</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="width: 150px;">Título:</td>
                <td>
                    <input type="text" name="title" style="width: 95%; padding: 5px;" placeholder="Ex: 🏁 Prova iniciada!" required>
                </td>
            </tr>
            <tr>
                <td>Mensagem:</td>
                <td>
                    <textarea name="message" style="width: 95%; min-height: 60px; padding: 5px;" placeholder="Ex: A prova de ICTS acabou de começar." required></textarea>
                </td>
            </tr>
            <tr>
                <td>URL (opcional):</td>
                <td>
                    <input type="text" name="url" style="width: 95%; padding: 5px;" placeholder="/resultado">
                </td>
            </tr>
            <tr>
                <td>Enviar para:</td>
                <td>
                    <label style="display: block; margin: 5px 0;">
                        <input type="radio" name="send_all" value="1" checked onchange="toggleTeamSelection()">
                        Todos os inscritos (geral)
                    </label>
                    <label style="display: block; margin: 5px 0;">
                        <input type="radio" name="send_all" value="0" onchange="toggleTeamSelection()">
                        Equipes específicas
                    </label>
                </td>
            </tr>
            <tr>
                <th colspan="2" style="height: 50px">
                    <input type="submit" value="📤 Enviar Notificação" style="padding: 10px 20px; font-size: 16px;">
                </th>
            </tr>
        </tbody>
    </table>

    <div id="teamSelection" style="display: none;">
        <table class="tablesorter">
            <thead>
                <tr>
                    <th colspan="3">
                        Selecionar Equipes
                        <button type="button" onclick="selectAllTeams()" style="margin-left: 20px; padding: 5px 10px;">Todas</button>
                        <button type="button" onclick="clearAllTeams()" style="margin-left: 10px; padding: 5px 10px;">Limpar</button>
                    </th>
                </tr>
                <tr>
                    <th style="width: 50px;">Sel</th>
                    <th>Carro</th>
                    <th>Equipe</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $i = 0;
                foreach ($equipes as $eq) {
                    $i++;
                ?>
                <tr style="<?= $i % 2 == 0 ? 'background: #f9f9f9;' : '' ?>">
                    <td style="text-align: center;">
                        <input type="checkbox" name="teams[]" value="<?= $eq->getEquipeId() ?>">
                    </td>
                    <td><?= $eq->getEquipeId() ?></td>
                    <td><?= substr($eq->getEquipe(), 0, 50) ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</form>

</div>

<script>
function toggleTeamSelection() {
    const sendAll = document.querySelector('input[name="send_all"]:checked').value === '1';
    const teamSelection = document.getElementById('teamSelection');
    teamSelection.style.display = sendAll ? 'none' : 'block';
}

function selectAllTeams() {
    const checkboxes = document.querySelectorAll('input[name="teams[]"]');
    checkboxes.forEach(cb => cb.checked = true);
}

function clearAllTeams() {
    const checkboxes = document.querySelectorAll('input[name="teams[]"]');
    checkboxes.forEach(cb => cb.checked = false);
}
</script>

<?php
Template::printFooter();
