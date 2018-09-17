<?php
//if ($_SERVER['REDIRECT_YEAR'] == '2017') die("Em manutenção!");

if (!isset($_SESSION)) session_start();

$pg = "ger";

include("consulta_tudo.php");

//Define os cabeçalhos da tabela para cada prova
$cabecalhos["ger"] = array(
	array("<div  >Doc / Seg / Mot</div>"),
	array("<div >Conforto</div>"),
	array("<div  >Relatório</div>"),
	array("<div  >Apresentação</div>"),
	array("<div  >A</div>"),
	array("<div  >V</div>"),
	array("<div  >SLA</div>"),
	array("<div >S&T</div>"),
	array("<div  >Tra</div>"),
	array("<div  >Enduro</div>"),	
	array("<div >Total</div>"));	
	?>
<!DOCTYPE html>
<html>




<body>
<table>

	
			<tr> 
				<th style='width:20px; border: #000000 1px solid;'><img src='img/trofeu.png'></th>
				<th style="width:20px; border: #000000 1px solid;">#</th> 
				<th style = 'border: #000000 1px solid;height:125px'>Equipe<br><p class = 'nomeEscola'>Escola</p></th> 
<?php

//loop para criar o cabeçalho
for($i = 0; $i<sizeOf($cabecalhos[$pg]); $i++){
	echo "	<th style = 'border: #000000 1px solid;'>".$cabecalhos[$pg][$i][0]."</th>\n";
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
echo"	<td>".$pos."º</td>\n";
	echo"	<td>$row[0]</td>\n";
	if($ehCelular == 1){
		echo"	<td>$row[2]<br>";
		echo"<p class = 'nomeEscola'><i>$row[1]</i></p></td>\n";	
	}else{
		echo"	<td style='text-align: left'>$row[2]&nbsp;&nbsp;&nbsp;";
		echo"<i class = 'nomeEscola'>$row[1]</i></td>\n";	
	}
	for($k=3;$k<14;$k++){

		echo "<td>".str_replace(".", ",", $row[$k])."</td>";
		
	}
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
		document.getElementById("ger").innerHTML = "<span style=color:yellow>" + document.getElementById('ger').innerHTML + "</span>";
	}
	</script>