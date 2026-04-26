<?php

use Baja\Model\EquipeQuery;
use Baja\Model\EventoQuery;
use Baja\Site\Template;

Template::printHeader("Notificações");
?>
    <script>
        console.log("=== notificacoes.php: setting up OneSignal ===");

        var pushStatus = -2;
        var pushTags = {};
        var tagPrefix = '<?= EventoQuery::getCurrentEvent()->getEventoId() ?>_';

        // Use the same OneSignalDeferred pattern as Template.php
        window.OneSignalDeferred = window.OneSignalDeferred || [];
        OneSignalDeferred.push(async function(OneSignal) {
            console.log("notificacoes.php: OneSignal ready");

            // Listen for subscription changes
            OneSignal.User.PushSubscription.addEventListener('change', function (subscription) {
                console.log("subscriptionChange:", subscription);
                var optedIn = subscription.current ? subscription.current.optedIn : false;
                pushStatus = optedIn;
                $("#pushEnabled").prop("disabled", false).prop("checked", pushStatus);
                updateUI();
            });

            // Listen for permission changes
            OneSignal.Notifications.addEventListener('permissionChange', function (permission) {
                console.log("permissionChange:", permission);
                if (permission !== 'granted') {
                    $("#pushEnabled").prop("checked", false);
                    pushStatus = false;
                    updateUI();
                }
            });

            // Get current subscription status
            var isSubscribed = OneSignal.User.PushSubscription.optedIn;
            console.log("isPushNotificationsEnabled:", isSubscribed);
            pushStatus = isSubscribed;
            $("#pushEnabled").prop("disabled", false).prop("checked", pushStatus);

            // Get tags
            try {
                var tags = await OneSignal.User.getTags();
                pushTags = tags || {};
                console.log("getTags retrieved on init:", pushTags);

                // If user is subscribed but doesn't have the psa tag yet, set it now
                // This ensures existing subscribers get the "send to all" notifications
                if (isSubscribed && !pushTags[tagPrefix + 'psa']) {
                    console.log("Setting missing psa tag for existing subscriber");
                    OneSignal.User.addTag(tagPrefix + 'psa', "1");
                    pushTags[tagPrefix + 'psa'] = "1";
                }
            } catch (e) {
                console.error("Error getting tags:", e);
                pushTags = {};
            }

            updateUI();

            // Expose functions to global scope for onchange handlers
            window.registerForPush = function() {
                console.log("registerForPush called, pushStatus:", pushStatus);
                if (pushStatus === false) {
                    // optIn() will trigger permission prompt if needed
                    OneSignal.User.PushSubscription.optIn().then(function() {
                        console.log("optIn successful");
                        // Set the psa tag for general notifications
                        OneSignal.User.addTag(tagPrefix + 'psa', "1");
                    }).catch(function(err) {
                        console.error("optIn error:", err);
                        $("#pushEnabled").prop("checked", false);
                    });
                } else {
                    console.log("Calling optOut()...");
                    OneSignal.User.PushSubscription.optOut().then(function() {
                        // Remove the psa tag when opting out
                        OneSignal.User.deleteTag(tagPrefix + 'psa');
                    }).catch(function(err) {
                        console.error("optOut error:", err);
                    });
                }
            };

            window.registerForCar = function(car) {
                car = String(tagPrefix + car);
                console.log("registerForCar called:", car);
                if (pushTags[car] == "1") {
                    OneSignal.User.deleteTag(car);
                    pushTags[car] = "0";
                } else {
                    // Use key, value format instead of object
                    OneSignal.User.addTag(car, "1");
                    pushTags[car] = "1";
                }
                updateUI();
            };
        });

        function updateUI() {
            var inputs = $('.tagInput').prop("disabled", !pushStatus);
            if (pushStatus) {
                inputs.each(function () {
                    $(this).prop("checked", pushTags[tagPrefix + $(this).attr("data-num")] == "1");
                });
            } else {
                inputs.prop("checked", false);
            }
        }
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

<!-- iOS Instructions -->
<div style="margin-top: 20px;">
    <button onclick="document.getElementById('iosInstructions').style.display = document.getElementById('iosInstructions').style.display === 'none' ? 'block' : 'none'" style="background: #007AFF; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-size: 16px;">
        📱 Instruções para iPhone/iPad
    </button>
    <div id="iosInstructions" style="display: none; margin-top: 15px; padding: 15px; background: #f0f8ff; border-radius: 10px; border: 1px solid #007AFF;">
        <h3 style="color: #007AFF; margin-top: 0;">Como ativar notificações no iPhone/iPad</h3>
        <p><strong>Requisitos:</strong> iOS 16.4+ ou iPadOS 16.4+ (Safari, Chrome ou Edge)</p>

        <ol style="line-height: 1.8;">
            <li>Abra este site no <strong>Safari</strong> do seu iPhone/iPad</li>
            <li>Toque no botão <strong>Compartilhar</strong> <small>(ícone de seta para cima em um quadrado)</small></li>
            <li>Role para baixo e toque em <strong>"Adicionar à Tela de Início"</strong></li>
            <li>Toque em <strong>"Adicionar"</strong> no canto superior direito</li>
            <li><strong>Importante:</strong> Abra o aplicativo pela <strong>Tela de Início</strong> (não pelo navegador)</li>
            <li>Volte a esta página de notificações pelo app da Tela de Início</li>
            <li>Ative as notificações usando os botões acima</li>
        </ol>

        <p style="background: #fff3cd; padding: 10px; border-radius: 5px; border-left: 4px solid #ffc107;">
            <small>⚠️ <strong>Nota:</strong> No iPhone/iPad, a permissão de notificação só aparece após você adicionar o site à Tela de Início e abrir o app a partir dele. Esta é uma exigência da Apple.</small>
        </p>
    </div>
</div>

<!-- Detect iOS and show auto -->
<script>
if (/iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream) {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            document.getElementById('iosInstructions').style.display = 'block';
        }, 1000);
    });
}
</script>

<?php
Template::printFooter();