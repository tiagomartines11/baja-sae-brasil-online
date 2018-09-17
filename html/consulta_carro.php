<?php

//variável que indica qual prova deverá ser mostrada
$car = isset($_GET['car']) ?  $_GET['car'] : "1";
$select = isset($_GET['select']) ?  $_GET['select'] : "-";

$pg = 'car';
include("consulta_geral.php");
//Carro
$dados = DB::queryRaw("SELECT * FROM equipes WHERE num = $car");
$vCarros['equipe'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0]; 

//S&T
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT p1, p2, cla, resultado  FROM
							(SELECT *, 
									IF(tempC1 IS NULL, CONCAT(distC1, ''), CONCAT(tempC1, ' s')) as p1,
									IF(tempC2 IS NULL, CONCAT(distC2, ''), CONCAT(tempC2, ' s')) as p2,									
									IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT *, 
								IF(melhorTemp IS NOT NULL, melhorTemp, 1000000) as ordem,
								IF(melhorTemp IS NOT NULL, melhorTemp, melhorDist) as resultado									
							FROM (SELECT *, 
								IF(tempC1 IS NOT NULL AND tempC2 IS NOT NULL, 
									IF(tempC1 > tempC2, tempC2, tempC1), 
										IF(tempC1 IS NULL, tempC2, tempC1)) as melhorTemp,
							IF(distC1 < distC2, distC2, distC1) as melhorDist
							FROM (SELECT *,
								(sus_1_temp + 4*sus_1_con + 10*sus_1_gat + 0*sus_1_chi) as tempC1,
								(sus_2_temp + 4*sus_2_con + 10*sus_2_gat + 0*sus_2_chi) as tempC2, 
								(sus_1_dist - 0*sus_1_con - 0*sus_1_gat - 0*sus_1_chi) as distC1, 
								(sus_2_dist - 0*sus_2_con - 0*sus_2_gat - 0*sus_2_chi) as distC2 							
							FROM equipes 
							LEFT JOIN sus ON num = sus_num) c1) c2
							ORDER BY ordem ASC, melhorDist DESC) c3)c4
							WHERE num = $car");
							
if($dados->num_rows > 0){							
	$vCarros['sus'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];
}else{
	$vCarros['sus'] = array('--','--','--','--');
}	

//Conforto
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT con_sta ,resultado, cla FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT * , con_nota as resultado
							FROM equipes
							LEFT JOIN con ON num = con_num
							ORDER BY con_nota DESC)c1 ) c2
							WHERE num = $car");
							
							
$vCarros['con'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];   

//Velocidade
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT CONCAT(ROUND(vel_1,2) ,' Km/h'), CONCAT(ROUND(vel_2,2), ' Km/h'), cla, resultado  FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT *, IF(vel_2 IS NULL,vel_1,IF(vel_1 > vel_2, vel_1 ,vel_2 )) as resultado 
							FROM equipes
							LEFT JOIN avf ON num = avf_num
							ORDER BY resultado DESC)c1 ) c2
							WHERE num = $car");

	
$vCarros['vel'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0]; 

//Aceleração
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT CONCAT(ace_1, ' s'), CONCAT(ace_2, ' s'), cla, resultado  FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT *, IF(ace_1 IS NULL, 999999, IF(ace_2 IS NULL,ace_1,IF(ace_1 < ace_2, ace_1 ,ace_2 ))) as resultado 
							FROM equipes
							LEFT JOIN avf ON num = avf_num
							ORDER BY resultado ASC)c1 ) c2
							WHERE num = $car");

	
$vCarros['ace'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0]; 

//Slalom
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT CONCAT(tempC1, ' s'), CONCAT(tempC2, ' s'), cla , resultado FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT *, IF(tempC1 IS NULL, 999999,IF(tempC2 IS NULL, tempC1,IF(tempC1 < tempC2, tempC1, tempC2)))  as resultado 
							FROM (SELECT  *, 
										(sla_1_temp + 2*sla_1_bol + 4*sla_1_con + 10*sla_1_gat) as tempC1, 
										(sla_2_temp + 2*sla_2_bol + 4*sla_2_con + 10*sla_2_gat) as tempC2 
										FROM equipes 
										LEFT JOIN sla ON num = sla_num) c
							ORDER BY resultado ASC)c1 ) c2
							WHERE num = $car");

	
$vCarros['sla'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];


//Tração
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT p1, p2, cla, resultado  FROM
							(SELECT *,
									IF(tra_1_temp IS NULL, CONCAT(tra_1_dist,' m'), CONCAT(tra_1_temp,' s')) as p1,
									IF(tra_2_temp IS NULL, CONCAT(tra_2_dist, ' m'), CONCAT(tra_2_temp, ' s')) as p2,
									IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT *, 
								IF(melhorTemp IS NOT NULL, melhorTemp, 1000000) as ordem,
								IF(melhorTemp IS NOT NULL, melhorTemp, melhorDist) as resultado									
							FROM (SELECT *, 
								IF(tra_1_temp IS NOT NULL AND tra_2_temp IS NOT NULL,
										IF(tra_1_temp > tra_2_temp, tra_2_temp, tra_1_temp),
											IF(tra_1_temp IS NULL, tra_2_temp, tra_1_temp)) as melhorTemp,
								IF(tra_1_dist < tra_2_dist, tra_2_dist, tra_1_dist) as melhorDist
							FROM equipes 
							LEFT JOIN tra ON num = tra_num) c
							ORDER BY ordem ASC, melhorDist DESC)c1 ) c2
							WHERE num = $car");

	
$vCarros['tra'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

//Enduro
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT CONCAT(resultado,' voltas'), cla FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM		
							(SELECT * , end_voltas as resultado
							FROM equipes
							LEFT JOIN end ON num = end_num
							ORDER BY end_voltas DESC)c1 ) c2
							WHERE num = $car");

	
$vCarros['end'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

DB::queryRaw("SET @NOK = '<span style=\'color:red\'> NOK </span>/';");
DB::queryRaw("SET @OK  = '<span style=\'color:green\'> OK </span>/';");
DB::queryRaw("SET @NR  = '<span> -- </span>/';");
//Segurança
$dados = DB::queryRaw("SELECT (IF(seg_nota IS NULL, 0, seg_nota)+ IF(doc_nota IS NULL, 0 , doc_nota)) as nota, 
							CONCAT(IF(doc_sta IS NULL, @NR,IF(doc_sta = 'Reprovado', @NOK, @OK)),  
							IF(abs_sta IS NULL, @NR,IF(abs_sta = 'Reprovado', @NOK, @OK)), 
							IF(mot_sta IS NULL, @NR,IF(mot_sta = 'Reprovado', @NOK, @OK)), 
							IF(seg_sta IS NULL, @NR,IF(seg_sta = 'Reprovado', @NOK, @OK))) 
							as status
							FROM equipes
							LEFT JOIN seg ON num = seg_num
							WHERE num = $car");

	
$vCarros['seg'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

//Freios
$dados = DB::queryRaw("SELECT fre_nota
							FROM equipes
							LEFT JOIN fre ON num = fre_num
							WHERE num = $car");

	
$vCarros['fre'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];


//Apresentação
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT resultado, cla
						FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM	(SELECT *, IF(apr_pres = 1, ROUND((apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges+0.00001)*".(_ETAPA == "NAC" ? 150 : 250)."/80, 2), ROUND((apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges+0.00001)*".(_ETAPA == "NAC" ? 150 : 250)."*0, 2)) as resultado
							FROM equipes
							LEFT JOIN apr ON num = apr_num
							ORDER BY resultado DESC) c1 ) c2
							WHERE num = $car");

	
$vCarros['apr'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

//Finais
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT resultado, cla
						FROM
							(SELECT *, IF(@anterior != resultado, @pos := @linha , @pos := @pos) as cla,
									@anterior:= resultado as v1, @linha:= @linha+1 as v2
							FROM	
							(SELECT *, ROUND((fin_pw + fin_ele +fin_fre +fin_des +fin_sus +fin_mkt +fin_cal +fin_ges)*30/80,2) as resultado
							FROM equipes
							LEFT JOIN fin ON num = fin_num
							ORDER BY resultado DESC) c1 ) c2
							WHERE num = $car");

	
$vCarros['fin'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

//Relatorio
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
$dados = DB::queryRaw("SELECT resultado, cla
						FROM
							(SELECT *, 
									if(res < 0, 0, res) as resultado,
									IF(@anterior != res, @pos := @linha , @pos := @pos) as cla,
									@anterior:= res as v1, @linha:= @linha+1 as v2
							FROM	
							(SELECT *, ROUND (rel_nota + rel_pen, 2) as res
							FROM equipes
							LEFT JOIN rel ON num = rel_num
							ORDER BY res DESC) c1 ) c2
							WHERE num = $car");

	
$vCarros['rel'] = mysqli_fetch_all($dados,MYSQLI_NUM)[0];

$vCarros['ger'][0] = "";




$vCarros['end'][2] = round($valores['enduro'], 2);
$vCarros['tra'][4] = round($valores['tracao'], 2);
$vCarros['sla'][4] = round($valores['slalom'], 2);
$vCarros['ace'][4] = round($valores['aceleracao'], 2);
$vCarros['vel'][4] = round($valores['velocidade'], 2);
$vCarros['sus'][4] = round($valores['sus'], 2);
$vCarros['ger'][2] = round($valores['total'], 2);
$vCarros['ger'][1] = round($valores['cla'], 2);

setcookie($select, $car , time() + 60 * 60 * 24*500);
	
//echo "<pre>";print_r($valores);echo "</pre>"; 

echo json_encode($vCarros, JSON_UNESCAPED_UNICODE);
?>