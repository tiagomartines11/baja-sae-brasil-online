<?php

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Site\Template;

Template::printHeader("Notificações");
?>
    <script>
        var pushStatus = -2;
        var pushTags = {};
        var tagPrefix = '<?= EventoQuery::getCurrentEvent()->getEventoId() ?>_';

        function registerForPush() {
            if (pushStatus === false) {
                OneSignal.push(["getNotificationPermission", function (permission) {
                    if (permission === "denied") {
                        alert("Você precisa desbloquear as notificações do nosso site em seu navegador. Clique no cadeado na barra de endereço para desbloquear.");
                        $("#pushEnabled").prop("checked", false);
                    } else {
                        OneSignal.push(["registerForPushNotifications"]);
                        OneSignal.push(["setSubscription", true]);
                    }
                }]);
            } else
                OneSignal.push(["setSubscription", false]);
        }

        function registerForCar(car) {
            car = tagPrefix + car;
            if (pushTags[car] == "1") {
                OneSignal.push(["deleteTag", car])
                pushTags[car] = "0"
            } else {
                OneSignal.push(["sendTag", car, 1])
                pushTags[car] = "1"
            }
            updateUI();
        }

        function updateUI() {
            for (var i = 0; i < 88; i++) {
                if (pushTags[i] && pushTags[i] == "1") {
                    OneSignal.push(["deleteTag", i])
                    pushTags[car] = "0"
                    OneSignal.push(["sendTag", tagPrefix + i, 1])
                    pushTags[tagPrefix + i] = "1"
                }
            }

            var inputs = $('.tagInput').prop("disabled", !pushStatus);
            if (pushStatus) inputs.each(function () {
                $(this).prop("checked", pushTags[tagPrefix + $(this).attr("data-num")] == "1")
            });
            else inputs.prop("checked", false);
        }

        function updateId() {
            OneSignal.getUserId(function (userId) {
                $('#userId').html("User ID: " + userId);
            });
        }

        OneSignal.push(function () {
            // If we're on an unsupported browser, do nothing
            if (!OneSignal.isPushNotificationsSupported()) {
                alert("Seu navegador não suporta notificações.");
                return;
            }

            // Occurs when the user's subscription changes to a new value.
            OneSignal.on('subscriptionChange', function (isSubscribed) {
                pushStatus = isSubscribed
                $("#pushEnabled").prop("disabled", false).prop("checked", pushStatus);
                if (isSubscribed) OneSignal.push(["sendTag", tagPrefix + "psa", 1])
                OneSignal.push(["getTags", function (tags) {
                    pushTags = tags;
                    updateUI();
                }]);
                updateId();
            });

            OneSignal.on('notificationPermissionChange', function (permissionChange) {
                var currentPermission = permissionChange.to;
                if (currentPermission != 'granted') $("#pushEnabled").prop("checked", false);
            });

            OneSignal.isPushNotificationsEnabled(function (isEnabled) {
                pushStatus = isEnabled;
                $("#pushEnabled").prop("disabled", false).prop("checked", pushStatus);
                if (isEnabled) OneSignal.push(["sendTag", tagPrefix + "psa", 1])
                OneSignal.push(["getTags", function (tags) {
                    pushTags = tags;
                    updateUI();
                }]);
                updateId();
            });
        });
    </script>
<table class="tablesorter-blue">
<tbody style="text-align: left"> 	
<tr class='tablesorter-ignoreRow'>
    <td style="vertical-align: middle; padding: 10px;font-size: 22px; font-weight:bold;">
		Ativar Notificações Gerais
	</td>
	<td style="text-align:center">
        <label class="switch"><input type="checkbox" id="pushEnabled" disabled onchange="registerForPush()"><div class="slider round"></div></label>
    </td>  
</tr>
<tr class='tablesorter-ignoreRow'>
    <td style="vertical-align: middle; padding: 10px; font-size: 22px; font-weight:bold;line-height:22px;" colspan=2>
    Escolha as equipes para receber notificações específicas:
	</td>
</tr>

	<?php foreach (EquipeQuery::create()->filterByEventoId(EventoQuery::getCurrentEvent()->getEventoId())->filterByPresente(1)->orderByEquipeId()->find() as $equipe) {
		if($ehCelular == 1){
			echo"<td>#".$equipe->getEquipeId()." - ".$equipe->getEquipe()."<br>";
			echo"<p class = 'nomeEscola'><i>".$equipe->getEscola()." </i></p></td>\n";	
		}else{
			echo"	<td style='text-align: left'>#".$equipe->getEquipeId()." - ".$equipe->getEquipe()."&nbsp;&nbsp;&nbsp;";
			echo"<i class = 'nomeEscola'>".$equipe->getEscola()." </i></td>\n";	
		}
		echo"
			<td  style='text-align:center'>
			<label class='switch'><input type='checkbox' class='tagInput' data-num='".$equipe->getEquipeId()."' disabled onchange='registerForCar(".$equipe->getEquipeId().")'><div class='slider round'></div></label>
			</td></tr>";
	}?>
	<tr><td colspan="2" style="font-size: 10px" id="userId"></td> </tr>
</tbody>
</table>
<?php
Template::printFooter();