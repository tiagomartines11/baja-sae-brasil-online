<?php
//if ($_SERVER['REDIRECT_YEAR'] == '2017') die("Em manutenção!");

//EQUIPES
$dados = DB::queryRaw("SELECT * FROM equipes");
$equipes = mysqli_fetch_all($dados,MYSQLI_ASSOC);

 if (isset($_COOKIE['car1'])){
		$car1 = $_COOKIE['car1'];
}
 if (isset($_COOKIE['car2'])){
		$car2 = $_COOKIE['car2'];
}
 if (isset($_COOKIE['car3'])){
		$car3 = $_COOKIE['car3'];
}
 if (isset($_COOKIE['car4'])){
		$car4 = $_COOKIE['car4'];
}
	$cabecalhos["car"] = array(1,1,1,1,1,1,1,1,1,1);
	$pg = "car";
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

	<link rel="icon" href="img/baja.png" type="image/png">	
	
	<link rel="stylesheet" href="css/bootstrap.min.css">
	<script src="js/bootstrap.min.js"></script>
	<link rel="stylesheet" href="css/bootstrap-select.css">
	<script src="js/bootstrap-select.js"></script>	
	<link class="theme" rel="stylesheet" href="css/theme.blue.css">		
	<!--[if IE]>
  		<script src="http://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
	<![endif]-->
</head>
<script id="js">
	function classificacao(cla, id){
		if(cla == 1){
			$(id).addClass("ouro");
			return "<p style='color:transparent'>1º</p></td>";
		}else if(cla == 2){
			$(id).addClass("prata");
			return "<p style='color:transparent'>2º</p></td>";
		}else if(cla == 3){
			$(id).addClass("bronze");
			return "<p style='color:transparent'>3º</p></td>";
		}else{ 
		$(id).removeClass("ouro");
		$(id).removeClass("prata");
		$(id).removeClass("bronze");
		return cla+"º";}
	}	
	
	$(document).ready(function(){
		$('#car1').change(function(){	
			dados("car1");
		});
		$('#car2').change(function(){	
			dados("car2");
		});
		$('#car3').change(function(){	
			dados("car3");
		});
		$('#car4').change(function(){	
			dados("car4");
		});
	$(function(){
		$("table").tablesorter({
			theme : 'blue',
			widgets: [ 'zebra'],
		});
	}); 		
		
	dados("car1");
	dados("car2");
	dados("car3");
	dados("car4");		
	});	 
function dados(id){
				if(id == "car1"){
					a=1; b=3;
				}else if(id == "car2"){
					a=4; b=6;
				}else if(id == "car3"){
					a=7; b=9;
				}else if(id == "car4"){
					a=10; b=12;
				}					
				$.ajax({async: false, url: "consulta_carro.php?car=" + document.getElementById(id).value + "&select=" + id, success: function(result){
					res = JSON.parse(result);
					 $.each( res, function( prova, valor ) {
							if(prova =="ace" || prova =="vel" || prova =="tra"|| prova =="sla" || prova =="sus"){	
								if(valor[0] != null){
									if(valor[1] != null){
										if(valor[0].split(" ")[0] == valor[3]){bold1 = "style='font-weight:bold'"; bold2 = ""}
										else{bold2 = "style='font-weight:bold'"; bold1 = ""}
										$("#"+prova+"-"+a).html("<span "+ bold1 +">1ª: "+valor[0] + "</span><br><span "+ bold2 +">2ª: " + valor[1] + "</span>");
									}else{
										$("#"+prova+"-"+a).html("<span style='font-weight:bold'>1ª: "+valor[0] + "</span><br>2ª: Não realizado");
									}
										$("#"+prova+"-"+b).html(classificacao(valor[2], "#"+prova+"-"+b));
										$("#"+prova+"-"+(b-1)).html(valor[4]);										
								}else{
									$("#"+prova+"-"+a).html("Não realizado");
									$("#"+prova+"-"+b).html("---");	
									$("#"+prova+"-"+(b-1)).html("---");										
								}
							}else if(prova == 'apr' || prova == 'fin'  || prova == 'rel'){
									if(valor[0] != null){
										$("#"+prova+"-"+a).html("---")
										$("#"+prova+"-"+(a+1)).html(valor[0]);
										$("#"+prova+"-"+b).html(classificacao(valor[1], "#"+prova+"-"+b));
									}else{
										if(prova == 'apr' || prova == 'rel')$("#"+prova+"-"+a).html("Não realizado");
										if(prova == 'fin')$("#"+prova+"-"+a).html("Não classificado");
										$("#"+prova+"-"+b).html("---");	
										$("#"+prova+"-"+(b-1)).html("---");											
									}
							}else if(prova == 'seg'){
									if(valor[0] != null){
										
										$("#"+prova+"-"+a).html("<span style='font-weight:bold;'>" + valor[1].slice(0, valor[1].length-2) + "</span>");
										$("#"+prova+"-"+(b-1)).html(valor[0]);
										$("#"+prova+"-"+b).html("---");											
									}else{
										$("#"+prova+"-"+a).html("Não realizado");
										$("#"+prova+"-"+b).html("---");								
									}
							}else if(prova == 'fre'){
									if(valor[0] != null){
										if( valor[0] == 'Aprovado'){cor = 'green'}
										else{cor = 'red'}
										$("#"+prova+"-"+a).html("<span style='font-weight:bold; color:"+cor+"'>" + valor[0] + "</span>");
										$("#"+prova+"-"+b).html("---");	
										$("#"+prova+"-"+(b-1)).html("---");	
									}else{
										$("#"+prova+"-"+a).html("Não realizado");
										$("#"+prova+"-"+b).html("---");								
									}
							}else if(prova == 'equipe'){
									$("#"+prova+"-"+a).html(valor[2]);
							}else if(prova == 'con'){
									if(valor[0] != null){
										if( valor[0] == 'Aprovado'){cor = 'green'}
										else{cor = 'red'}
										if(valor[1] == null){valor[1] = ""}
										$("#"+prova+"-"+a).html("<span style='font-weight:bold; color:"+cor+"'>" + valor[0] + "</span>");
										$("#"+prova+"-"+(b-1)).html(valor[1]);
										$("#"+prova+"-"+b).html(classificacao(valor[2], "#"+prova+"-"+b));	
									}else{
										$("#"+prova+"-"+a).html("Não realizado");
										$("#"+prova+"-"+b).html("---");								
									}
							}else if(prova == 'end' || prova == 'ger'){
									if(valor[0] != null){
										$("#"+prova+"-"+a).html(valor[0]);
										$("#"+prova+"-"+b).html(classificacao(valor[1], "#"+prova+"-"+b));
										$("#"+prova+"-"+(b-1)).html(valor[2]);
										if(prova == 'ger'){$("#"+prova+"-"+a).html("---");}										
									}else{
										if(prova == 'end')$("#"+prova+"-"+a).html("Não realizado");
										$("#"+prova+"-"+b).html("---");
										$("#"+prova+"-"+(b-1)).html("---");
												
									}
							}else if(prova == 'pen'){
									$("#"+prova+"-"+(a+1)).html(valor[0]);
									{$("#"+prova+"-"+b).html("---");}
									{$("#"+prova+"-"+a).html("---");}											
								}		
					  });	 
				}})		
}	
</script>

<body>
<?php include("menu.php");?>
<?php Template::printGA(); ?>

		<tr class="tablesorter-ignoreRow">	
			<th colspan=2></th>
			<th colspan=3 nowrap style='border: 1px solid white; border-bottom: none'>
				<select id="car1" style="width:120px; color:black" class="selectpicker" data-width="fit" data-mobile="true" title="Carro...">
					<?php
						foreach ($equipes as $row){
							if($row[num] == $car1){
								$a = "selected";
							}else{
								$a = "";
							}		
							echo "<option $a value='$row[num]' title='#$row[num]'>#$row[num] - $row[equipe]</option>";
						}
					?>
				</select>
			</th>
			<th colspan=3 nowrap  style='border: 1px solid white; border-bottom: none'>
				<select id="car2" style="width:120px; color:black" class="selectpicker" data-width="fit" data-mobile="true" title="Carro...">
					<?php
						foreach ($equipes as $row){
							if($row[num] == $car2){
								$a = "selected";
							}else{
								$a = "";
							}								
							echo "<option $a value='$row[num]' title='#$row[num]'>#$row[num] - $row[equipe]</option>";
						}
					?>
				</select>				
			</th>
			
						<th colspan=3 nowrap  style='border: 1px solid white; border-bottom: none'>
				<select id="car3" style="width:120px; color:black" class="selectpicker" data-width="fit" data-mobile="true" title="Carro...">
					<?php
						foreach ($equipes as $row){
							if($row[num] == $car3){
								$a = "selected";
							}else{
								$a = "";
							}								
							echo "<option $a value='$row[num]' title='#$row[num]'>#$row[num] - $row[equipe]</option>";
						}
					?>
				</select>				
			</th>
			<th colspan=3 nowrap  style='border: 1px solid white; border-bottom: none'>
				<select id="car4" style="width:120px; color:black" class="selectpicker" data-width="fit" data-mobile="true" title="Carro...">
					<?php
						foreach ($equipes as $row){
							if($row[num] == $car4){
								$a = "selected";
							}else{
								$a = "";
							}								
							echo "<option $a value='$row[num]' title='#$row[num]'>#$row[num] - $row[equipe]</option>";
						}
					?>
				</select>				
			</th>
		</tr>
		<tr class="tablesorter-ignoreRow">	
			<th colspan=2></th>
			<th colspan=3 id="equipe-1"  style='border: 1px solid white; border-bottom: none; border-top: none'></th>
			<th colspan=3 id="equipe-4"  style='border: 1px solid white; border-bottom: none; border-top: none'></th>
			<th colspan=3 id="equipe-7"  style='border: 1px solid white; border-bottom: none; border-top: none'></th>
			<th colspan=3 id="equipe-10" style='border: 1px solid white; border-bottom: none; border-top: none'></th>				
		</tr>			
		<tr class="tablesorter-ignoreRow">	
			<th></th>					
			<th></th>
			<th style='border: 1px solid white; border-right: none; border-top: none'>Resultado</th>
			<th>Pts</th>			
			<th><img src='img/trofeu.png' height=15px></th>
			<th style='border: 1px solid white; border-right: none; border-top: none'>Resultado</th>
			<th>Pts</th>			
			<th><img src='img/trofeu.png' height=15px></th>
			
			<th style='border: 1px solid white; border-right: none; border-top: none'>Resultado</th>
			<th>Pts</th>			
			<th><img src='img/trofeu.png' height=15px></th>
			<th style='border: 1px solid white; border-right: none; border-top: none'>Resultado</th>
			<th>Pts</th>			
			<th><img src='img/trofeu.png' height=15px></th>			
		</tr>
		</thead> 	
	<tbody>
			<tr>			
			<td nowrap colspan= 14></td>
		</tr>
		<tr>
			<th rowspan=3 ><div  style="transform: rotate(-90deg);margin: 0 -30px;line-height:115%">Segurança</div></th>
			<th style="max-width:200px; width:12%">Doc/Abast/Mot/Seg</th>
			<td nowrap id="seg-1"></td>
			<td nowrap id="seg-2"></td>		
			<td nowrap id="seg-3"></td>
			<td nowrap id="seg-4"></td>	
			<td nowrap id="seg-5"></td>
			<td nowrap id="seg-6"></td>		
			<td nowrap id="seg-7"></td>
			<td nowrap id="seg-8"></td>
			<td nowrap id="seg-9"></td>
			<td nowrap id="seg-10"></td>		
			<td nowrap id="seg-11"></td>
			<td nowrap id="seg-12"></td>					
		</tr>
		<tr>
			<th  style='line-height:125%'>Conforto</th>
			<td nowrap id="con-1"></td>
			<td nowrap id="con-2"></td>		
			<td nowrap id="con-3"></td>
			<td nowrap id="con-4"></td>
			<td nowrap id="con-5"></td>
			<td nowrap id="con-6"></td>		
			<td nowrap id="con-7"></td>
			<td nowrap id="con-8"></td>	
			<td nowrap id="con-9"></td>
			<td nowrap id="con-10"></td>		
			<td nowrap id="con-11"></td>
			<td nowrap id="con-12"></td>				
		</tr>			
		<tr>
			<th  style='line-height:125%'>Freios</th>
			<td nowrap id="fre-1"></td>
			<td nowrap id="fre-2"></td>		
			<td nowrap id="fre-3"></td>
			<td nowrap id="fre-4"></td>	
			<td nowrap id="fre-5"></td>
			<td nowrap id="fre-6"></td>		
			<td nowrap id="fre-7"></td>
			<td nowrap id="fre-8"></td>
			<td nowrap id="fre-9"></td>
			<td nowrap id="fre-10"></td>		
			<td nowrap id="fre-11"></td>
			<td nowrap id="fre-12"></td>			
		</tr>			
		<tr>			
			<td nowrap colspan= 14></td>
		</tr>	
		<tr <?php if (_ETAPA != 'NAC') echo "style='display: none;'" ?>>
            <?php if (_ETAPA == 'NAC') echo "<th rowspan=3 ><div  style=\"transform: rotate(-90deg);margin: 0 -30px;\">Projeto</div></th>" ?>
			<th>Relatório</th>
			<td nowrap id="rel-1"></td>
			<td nowrap id="rel-2"></td>		
			<td nowrap id="rel-3"></td>
			<td nowrap id="rel-4"></td>	
			<td nowrap id="rel-5"></td>
			<td nowrap id="rel-6"></td>		
			<td nowrap id="rel-7"></td>
			<td nowrap id="rel-8"></td>
			<td nowrap id="rel-9"></td>
			<td nowrap id="rel-10"></td>		
			<td nowrap id="rel-11"></td>
			<td nowrap id="rel-12"></td>				
		</tr>			
		<tr>
            <?php if (_ETAPA != 'NAC') echo "<th rowspan=1 ><div  style=\"transform: rotate(-90deg);margin: 0 -30px;\">Pr</div></th>" ?>
            <th>Apresentação</th>
			<td nowrap id="apr-1"></td>
			<td nowrap id="apr-2"></td>		
			<td nowrap id="apr-3"></td>
			<td nowrap id="apr-4"></td>
			<td nowrap id="apr-5"></td>
			<td nowrap id="apr-6"></td>		
			<td nowrap id="apr-7"></td>
			<td nowrap id="apr-8"></td>
			<td nowrap id="apr-9"></td>
			<td nowrap id="apr-10"></td>		
			<td nowrap id="apr-11"></td>
			<td nowrap id="apr-12"></td>				
		</tr>			
		<tr <?php if (_ETAPA != 'NAC') echo "style='display: none;'" ?>>
            <th>Finais de projeto</th>
			<td nowrap id="fin-1"></td>
			<td nowrap id="fin-2"></td>		
			<td nowrap id="fin-3"></td>
			<td nowrap id="fin-4"></td>
			<td nowrap id="fin-5"></td>
			<td nowrap id="fin-6"></td>		
			<td nowrap id="fin-7"></td>
			<td nowrap id="fin-8"></td>
			<td nowrap id="fin-9"></td>
			<td nowrap id="fin-10"></td>		
			<td nowrap id="fin-11"></td>
			<td nowrap id="fin-12"></td>					
		</tr>			
		<tr>			
			<td nowrap colspan= 14></td>
		</tr>
	
		<tr <?php if (_ETAPA != 'NAC') echo "style='display: none;'" ?>>
            <?php if (_ETAPA == 'NAC') echo "<th rowspan= 5 ><div  style=\"transform: rotate(-90deg);margin: 0 -30px;\">Dinâmicas</div></th>" ?>
			<th>Aceleração</th>
			<td nowrap id="ace-1"></td>
			<td nowrap id="ace-2"></td>		
			<td nowrap id="ace-3"></td>
			<td nowrap id="ace-4"></td>
			<td nowrap id="ace-5"></td>
			<td nowrap id="ace-6"></td>		
			<td nowrap id="ace-7"></td>
			<td nowrap id="ace-8"></td>
			<td nowrap id="ace-9"></td>
			<td nowrap id="ace-10"></td>		
			<td nowrap id="ace-11"></td>
			<td nowrap id="ace-12"></td>				
		</tr>
		
		<tr <?php if (_ETAPA != 'NAC') echo "style='display: none;'" ?>>
			<th>Velocidade</th>
			<td nowrap id="vel-1"></td>
			<td nowrap id="vel-2"></td>		
			<td nowrap id="vel-3"></td>
			<td nowrap id="vel-4"></td>	
			<td nowrap id="vel-5"></td>
			<td nowrap id="vel-6"></td>		
			<td nowrap id="vel-7"></td>
			<td nowrap id="vel-8"></td>
			<td nowrap id="vel-9"></td>
			<td nowrap id="vel-10"></td>		
			<td nowrap id="vel-11"></td>
			<td nowrap id="vel-12"></td>					
		</tr>
		<tr>
            <?php if (_ETAPA != 'NAC') echo "<th rowspan= 3 ><div  style=\"transform: rotate(-90deg);margin: 0 -30px;\">Dinâmicas</div></th>" ?>
            <th> <?php if (_ETAPA != 'NAC') echo "Lama"; else echo "Tração"; ?></th>
			<td nowrap id="tra-1"></td>
			<td nowrap id="tra-2"></td>
			<td nowrap id="tra-3"></td>
			<td nowrap id="tra-4"></td>
			<td nowrap id="tra-5"></td>
			<td nowrap id="tra-6"></td>
			<td nowrap id="tra-7"></td>
			<td nowrap id="tra-8"></td>
			<td nowrap id="tra-9"></td>
			<td nowrap id="tra-10"></td>
			<td nowrap id="tra-11"></td>
			<td nowrap id="tra-12"></td>
		</tr>
		<tr>
			<th>S&T</th>
			<td nowrap id="sus-1"></td>
			<td nowrap id="sus-2"></td>
			<td nowrap id="sus-3"></td>
			<td nowrap id="sus-4"></td>
			<td nowrap id="sus-5"></td>
			<td nowrap id="sus-6"></td>
			<td nowrap id="sus-7"></td>
			<td nowrap id="sus-8"></td>
			<td nowrap id="sus-9"></td>
			<td nowrap id="sus-10"></td>
			<td nowrap id="sus-11"></td>
			<td nowrap id="sus-12"></td>
		</tr>
		<tr>
			<th>Slalom</th>
			<td nowrap id="sla-1"></td>
			<td nowrap id="sla-2"></td>
			<td nowrap id="sla-3"></td>
			<td nowrap id="sla-4"></td>
			<td nowrap id="sla-5"></td>
			<td nowrap id="sla-6"></td>
			<td nowrap id="sla-7"></td>
			<td nowrap id="sla-8"></td>
			<td nowrap id="sla-9"></td>
			<td nowrap id="sla-10"></td>
			<td nowrap id="sla-11"></td>
			<td nowrap id="sla-12"></td>
		</tr>
		<tr>
			<td nowrap colspan= 14></td>
		</tr>
		<tr>
			<th colspan=2>Enduro</th>
			<td nowrap id="end-1"></td>
			<td nowrap id="end-2"></td>
			<td nowrap id="end-3"></td>
			<td nowrap id="end-4"></td>
			<td nowrap id="end-5"></td>
			<td nowrap id="end-6"></td>
			<td nowrap id="end-7"></td>
			<td nowrap id="end-8"></td>
			<td nowrap id="end-9"></td>
			<td nowrap id="end-10"></td>
			<td nowrap id="end-11"></td>
			<td nowrap id="end-12"></td>
		</tr>
<tr>
			<td nowrap colspan= 14></td>
		</tr>

		<tr>
			<th colspan=2>Geral</th>
			<td nowrap id="ger-1"></td>
			<td nowrap id="ger-2"></td>
			<td nowrap id="ger-3"></td>
			<td nowrap id="ger-4"></td>
			<td nowrap id="ger-5"></td>
			<td nowrap id="ger-6"></td>
			<td nowrap id="ger-7"></td>
			<td nowrap id="ger-8"></td>
			<td nowrap id="ger-9"></td>
			<td nowrap id="ger-10"></td>
			<td nowrap id="ger-11"></td>
			<td nowrap id="ger-12"></td>
		</tr>
	</tbody>
</table>

<script type="text/javascript" src="js/menu.js"></script>
<script>
	cel = "<?php echo $ehCelular ; ?>";
	document.getElementById("titulo").innerHTML = document.getElementById('car').innerHTML;
	if(cel != 1){
		document.getElementById("car").innerHTML = "<span style=color:yellow>" + document.getElementById('car').innerHTML + "</span>";
	}

</script>