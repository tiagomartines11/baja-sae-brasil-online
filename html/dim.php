<?php
//if (_URL == '2017') die("Em manutenção!");

if (!isset($_SESSION)) session_start();

$pg = "ger";

include("consulta_geral.php");

//Define os cabeçalhos da tabela para cada prova
if (_ETAPA == 'NAC')
$cabecalhos["ger"] = array(
    array("<div  style='transform: rotate(-90deg);margin: 0 -70px;'>Aceleração</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Velocidade</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Slalom</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>S&T</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Tração</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Total</div>"));
else
$cabecalhos["ger"] = array(
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Slalom</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>S&T</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Lama</div>"),
    array("<div  style='transform: rotate(-90deg);margin: 0 -30px;'>Total</div>"));
	?>
<!DOCTYPE html>
<html>
<head>
    <link rel="manifest" href="/manifest.json">
    <script src="https://cdn.onesignal.com/sdks/OneSignalSDK.js" async></script>
    <script>
        var OneSignal = window.OneSignal || [];
        OneSignal.push(["init", {
            appId: "f4b8f501-88bb-49fe-b209-712ae98da3e2",
            autoRegister: true,
            notifyButton: {
                enable: false /* Set to false to hide */
            },
            welcomeNotification: {
                "title": "Baja SAE Brasil Online",
                "message": "Obrigado por se inscrever!",
                "url": "<?php echo _URL; ?>/notificacoes.php"
            },
            persistNotification: false,
            safari_web_id: "web.onesignal.auto.246fdfe2-a404-4d40-aa8a-d2b211d431d5"
        }]);
        var tagPrefix = '<?php echo _URL; ?>_';
        OneSignal.push(function() {
            OneSignal.on('subscriptionChange', function (isSubscribed) {
                if (isSubscribed) OneSignal.push(["sendTag", tagPrefix + "psa", 1])
            });
        });
    </script>
	<title><?php echo _TITULO ?></title>
	<meta name="viewport" content="width=device-width" />
	<meta charset="UTF-8" />
	<script src="js/jquery-latest.min.js"></script>
	<script src="js/jquery.tablesorter.js"></script>
	<script src="js/jquery.tablesorter.widgets.js"></script>
	<link class="theme" rel="stylesheet" href="css/theme.blue.css">	
	<link rel="icon" href="img/baja.png" type="image/png">	
	<!--[if IE]>
  		<script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->
</head>

	
<script id="js">
	$(function(){
		$("table").tablesorter({
			theme : 'blue',
			widgets: [ 'zebra', 'stickyHeaders'],
			<?php if($pg != "seg" && $pg  != "fre") echo "sortList: [[0,0]]" ?>
		});
	}); 
</script>

<body>
<?php Template::printGA(); ?>

<?php 
	include("menu.php");
?>	
			<tr> 

				<th style="width:20px; border: #cdcdcd 1px solid;">#</th> 
				<th style = 'border: #cdcdcd 1px solid;height:125px'>Equipe<br><p class = 'nomeEscola'>Escola</p></th> 
<?php

//loop para criar o cabeçalho
for($i = 0; $i<sizeOf($cabecalhos[$pg]); $i++){
	echo "	<th style = 'border: #cdcdcd 1px solid;'>".$cabecalhos[$pg][$i][0]."</th>\n";
}
echo "</tr>\n</thead><tbody>";
$linha = 0;	
$pos = 0;
$resultado_anterior = "";
foreach ($valores as $row){
	$linha++;
		if($resultado_anterior != $row[8]){
			$pos = $linha;
		}
	
	$resultado_anterior = $row[8];
	
	echo"	<td>$row[0]</td>\n";
	if($ehCelular == 1){
		echo"	<td>$row[2]<br>";
		echo"<p class = 'nomeEscola'><i>$row[1]</i></p></td>\n";	
	}else{
		echo"	<td style='text-align: left'>$row[2]&nbsp;&nbsp;&nbsp;";
		echo"<i class = 'nomeEscola'>$row[1]</i></td>\n";	
	}
    if (_ETAPA == 'NAC') echo"	<td>".round($row[9],2)."</td>";
    if (_ETAPA == 'NAC') echo"	<td>".round($row[10],2)."</td>";
	echo"	<td>".round($row[11],2)."</td>";
	echo"	<td>".round($row[12],2)."</td>";
	echo"	<td>".round($row[13],2)."</td>";
	echo"	<td>$row[7]</td>";		
	echo"</tr>\n";			
}	
?>
		</tbody> 
	</table>
</body>
</html>

	<script type="text/javascript" src="js/menu.js"></script>	
	
	<script id="js">
	cel = "<?php echo $ehCelular ; ?>";
		document.getElementById("titulo").innerHTML = document.getElementById('<?php echo $pg; ?>').innerHTML;
	if(cel != 1){
		document.getElementById("dinamicas").innerHTML = "Dinâmicas -> <span style=color:yellow>" + document.getElementById('dim').innerHTML + "</span>";
	}
	</script>