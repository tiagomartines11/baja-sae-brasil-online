<?php

$enduro = DB::queryRaw("SELECT MIN(end_voltas) as endMIN, MAX(end_voltas) as endMax FROM end");
$velocidade = DB::queryRaw("SELECT MIN(resultado) as velMIN, MAX(resultado) as velMax 
								FROM (SELECT  IF(vel_2 IS NULL,vel_1,IF(vel_1 > vel_2, vel_1 ,vel_2 )) as resultado 
								FROM avf) c1");
$aceleracao = DB::queryRaw("SELECT MIN(resultado) as aceMIN, MAX(resultado) as aceMax 
								FROM (SELECT  IF(ace_2 IS NULL,ace_1,IF(ace_1 < ace_2, ace_1 ,ace_2 )) as resultado 
								FROM avf) c1");
$slalom= DB::queryRaw("SELECT MIN(resultado) as slaMIN, 
								IF(MAX(resultado) > 2.5*MIN(resultado),2.5*MIN(resultado), MAX(resultado)) as slaMax 
								FROM (SELECT *, 
									IF(tempC2 IS NULL, tempC1,IF(tempC1 < tempC2, tempC1, tempC2)) as resultado 
								FROM (SELECT  *, 
									(sla_1_temp + 2*sla_1_bol + 5*sla_1_con + 10*sla_1_gat) as tempC1, 
									(sla_2_temp + 2*sla_2_bol + 5*sla_2_con + 10*sla_2_gat) as tempC2 
								FROM equipes INNER JOIN sla ON num = sla_num) c) c1");
								
$sus = DB::queryRaw("SELECT MIN(melhorTemp) as susTempMin, IF(MAX(melhorTemp) > 2.5*MIN(melhorTemp), 2.5*MIN(melhorTemp), MAX(melhorTemp)) as susTempMax	 					
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
							INNER JOIN sus ON num = sus_num) c) a");

$tracao = DB::queryRaw("SELECT MIN(melhorTemp) as traTempMin, MAX(melhorTemp) as traTempMax, MIN(melhorDist) as traDistMin, MAX(melhorDist) as traDistMax								
							FROM (SELECT *, 
								IF(tra_1_temp IS NOT NULL AND tra_2_temp IS NOT NULL,
										IF(tra_1_temp > tra_2_temp, tra_2_temp, tra_1_temp),
											IF(tra_1_temp IS NULL, tra_2_temp, tra_1_temp)) as melhorTemp,
								IF(tra_1_dist IS NOT NULL AND tra_2_dist IS NOT NULL,
										IF(tra_1_dist > tra_2_dist, tra_1_dist, tra_2_dist),
											IF(tra_1_dist IS NULL, tra_2_dist, tra_1_dist)) as melhorDist
							FROM equipes 
							INNER JOIN tra ON num = tra_num) c");							

$dadosEnd = mysqli_fetch_array ($enduro,MYSQLI_ASSOC); 
$dadosVel = mysqli_fetch_array ($velocidade,MYSQLI_ASSOC);  
$dadosAce = mysqli_fetch_array ($aceleracao,MYSQLI_ASSOC); 
$dadosSla = mysqli_fetch_array ($slalom,MYSQLI_ASSOC);
$dadosSus = mysqli_fetch_array ($sus,MYSQLI_ASSOC);
$dadosTra = mysqli_fetch_array ($tracao,MYSQLI_ASSOC);

DB::queryRaw("SET @endMIN = ".$dadosEnd['endMIN'].";");
DB::queryRaw("SET @endMax = ".$dadosEnd['endMax'].";");
DB::queryRaw("SET @velMIN = ".$dadosVel['velMIN'].";");
DB::queryRaw("SET @velMax = ".$dadosVel['velMax'].";");
DB::queryRaw("SET @aceMIN = ".$dadosAce['aceMIN'].";");
DB::queryRaw("SET @aceMax = ".$dadosAce['aceMax'].";");
DB::queryRaw("SET @slaMIN = ".$dadosSla['slaMIN'].";");
DB::queryRaw("SET @slaMax = ".$dadosSla['slaMax'].";");
DB::queryRaw("SET @susTempMax = ".$dadosSus['susTempMax'].";");
DB::queryRaw("SET @susTempMin = ".$dadosSus['susTempMin'].";");

DB::queryRaw("SET @traTempMax = ".$dadosTra['traTempMax'].";");
DB::queryRaw("SET @traTempMin = ".$dadosTra['traTempMin'].";");
DB::queryRaw("SET @traDistMax = ".$dadosTra['traDistMax'].";");
DB::queryRaw("SET @traDistMin = ".$dadosTra['traDistMin'].";");
if($dadosTra['traTempMax'] == NULL){
	$traMetodo =  "metodoA";
}else if($dadosTra['traDistMax'] == NULL){
	$traMetodo =  "metodoB";
}else{
	$traMetodo =  "metodoC";	
}	
DB::queryRaw("SET @traMetodo = ".$traMetodo.";");

$consulta = "	FROM (SELECT *, ROUND(tracao + sus + velocidade + aceleracao + slalom, 2) as dinamicasSum, (tracao + sus + velocidade + aceleracao + slalom) as dinamicas	
	FROM (SELECT *, 	
						IF(slalomA < 0, 0, slalomA) as slalom,
						IF(tra IS NULL, 0 , tra) as tracao,
						IF(susA IS NULL, 0 , susA) as sus
	FROM (SELECT *, 	IF(TraMelhorTemp IS NOT NULL, 
	                       "._PTS_TRACAO."*@traTempMin/TraMelhorTemp,
	                       "._PTS_TRACAO."*(@traTempMin/@traTempMax)*(TraMelhorDist)/15
	                        ) as tra,
						 IF((100*(60.799 - SusMelhorTemp)/(60.799 - 36.076) - IF(SusMelhorTemp = sus_1_temp, sus_1_con*4, sus_2_con*4) )>0,
						  (100*(60.799 - SusMelhorTemp)/(60.799 - 36.076) - IF(SusMelhorTemp = sus_1_temp, sus_1_con*4, sus_2_con*4) ),
						  0)
						  as susA
						
	FROM (SELECT *, 	
						
						IF(SusMelhorTempV1 > 2.5*@susTempMin, 2.5*@susTempMin, SusMelhorTempV1) as SusMelhorTemp,
						IF(TraMelhorTemp IS NOT NULL, TraMelhorTemp, TraMelhorDist) as resTra		
	FROM (SELECT *,		
						IF(SusTempC1 IS NOT NULL AND SusTempC2 IS NOT NULL, 
						IF(SusTempC1 > SusTempC2, SusTempC2, SusTempC1), 
						IF(SusTempC1 IS NULL, SusTempC2, SusTempC1)) as SusMelhorTempV1,
						
						IF(sus_1_dist IS NOT NULL AND sus_2_dist IS NOT NULL, 
						IF(sus_1_dist < sus_2_dist, sus_2_dist, sus_1_dist),  
						IF(sus_1_dist IS NULL, sus_2_dist, sus_1_dist)) as SusMelhorDist,
						
						IF(tra_1_temp IS NOT NULL AND tra_2_temp IS NOT NULL, 
						IF(tra_1_temp > tra_2_temp, tra_2_temp, tra_1_temp),
						IF(tra_1_temp IS NULL, tra_2_temp, tra_1_temp)) as TraMelhorTemp,
						
							IF(tra_1_dist IS NOT NULL AND tra_2_dist IS NOT NULL,
										IF(tra_1_dist > tra_2_dist, tra_1_dist, tra_2_dist),
											IF(tra_1_dist IS NULL, tra_2_dist, tra_1_dist)) as TraMelhorDist,
						IF("._PTS_SLALON."*(@slaMax - resSla)/(@slaMax - @slaMIN) IS NULL, 0, "._PTS_SLALON."*(@slaMax - resSla)/(@slaMax - @slaMIN)) as slalomA								
	FROM (SELECT *, 	IF(SlaTempC2 IS NULL, SlaTempC1,IF(SlaTempC1 < SlaTempC2, SlaTempC1, SlaTempC2)) as resSla,
						
						(sus_1_temp + 4*sus_1_con + 10*sus_1_gat + 0*sus_1_chi) as SusTempC1,
						(sus_2_temp + 4*sus_2_con + 10*sus_2_gat + 0*sus_2_chi) as SusTempC2
					
	FROM (SELECT *,  	(sla_1_temp + 2*sla_1_bol + 4*sla_1_con + 10*sla_1_gat) as SlaTempC1, 
						(sla_2_temp + 2*sla_2_bol + 4*sla_2_con + 10*sla_2_gat) as SlaTempC2,
						IF("._PTS_ACELERACAO."*(@aceMax - resAce)/(@aceMax - @aceMin) IS NULL, 0, "._PTS_ACELERACAO."*(@aceMax - resAce)/(@aceMax - @aceMin)) as aceleracao						
	FROM (SELECT *, IF(ace_2 IS NULL,ace_1,IF(ace_1 < ace_2, ace_1 ,ace_2 )) as resAce, 
					IF("._PTS_VELOCIDADE."*(@velMIN - resVel)/(@velMIN - @velMax) IS NULL,	0 , "._PTS_VELOCIDADE."*(@velMIN - resVel)/(@velMIN - @velMax))  as velocidade	
	FROM (SELECT *, IF(vel_2 IS NULL,vel_1,IF(vel_1 > vel_2, vel_1 ,vel_2 )) as resVel	
	FROM (SELECT *,	IF("._PTS_ENDURO."*(@endMIN - end_voltas)/(@endMIN - @endMax) IS NULL, 0 , "._PTS_ENDURO."*(@endMIN - end_voltas)/(@endMIN - @endMax)) as enduro, 
					ROUND(fin + apr + relatorio,2 ) as estaticasSum,  (fin + apr + relatorio) as estaticas
	FROM (SELECT *, IF((fin_pw + fin_ele +fin_fre +fin_des +fin_sus +fin_mkt +fin_cal +fin_ges)*30/80 IS NULL, 0, (fin_pw + fin_ele +fin_fre +fin_des +fin_sus +fin_mkt +fin_cal +fin_ges)*30/80) as fin
	FROM (SELECT *, IF(IF(apr_pres = 1, (apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges)*".(_ETAPA == "NAC" ? 150 : 250)."/80, (apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges)*".(_ETAPA == "NAC" ? 150 : 250)."*0) IS NULL, 0, IF(apr_pres = 1, (apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges)*".(_ETAPA == "NAC" ? 150 : 250)."/80, (apr_pw + apr_ele +apr_fre +apr_des +apr_sus +apr_mkt +apr_cal +apr_ges)*".(_ETAPA == "NAC" ? 150 : 250)."*0)) as apr,
					IF(relatorioNEG < 0, 0, relatorioNEG) as relatorio
	FROM (SELECT *, (IF(rel_nota IS NULL, 0, rel_nota) + IF(rel_pen IS NULL, 0, rel_pen)) as relatorioNEG, 
					IF(con_nota IS NULL, 0 , con_nota) as conforto
	FROM (SELECT *, (seg_notaC + doc_notaC) as seguranca
	FROM (SELECT *, IF(seg_nota IS NULL, 0 , seg_nota) as seg_notaC, IF(doc_nota IS NULL, 0 , doc_nota) as doc_notaC
	FROM equipes
	LEFT JOIN seg ON num = seg_num) c1
	LEFT JOIN con ON num = con_num) c4
	LEFT JOIN rel ON num = rel_num) c5
	LEFT JOIN apr ON num = apr_num) c6
	LEFT JOIN fin ON num = fin_num) c7
	LEFT JOIN end ON num = end_num) c8
	LEFT JOIN avf ON num = avf_num) c9) c10
	LEFT JOIN sla ON num = sla_num) c11
	LEFT JOIN sus ON num = sus_num) c12
	LEFT JOIN tra ON num = tra_num) c13) c14) c15) c16) c17
	";
if($pg == 'ger'){$dados = DB::queryRaw("SELECT num, escola, equipe, seguranca, conforto, relatorio, (fin + apr), aceleracao, velocidade, slalom, sus , tracao  ,enduro,
		IF(ROUND(seguranca + conforto + estaticas + enduro + dinamicas+ 0.00001,2) < 0, 0, ROUND(seguranca + conforto + estaticas + enduro + dinamicas+ 0.00001,2)) as total".$consulta." order by total DESC, num ASC");
		$valores = mysqli_fetch_all($dados,MYSQLI_BOTH);}
		
if($pg == 'car'){
DB::queryRaw("SET @pos = 1;");
DB::queryRaw("SET @anterior = 0;");
DB::queryRaw("SET @linha = 1;");
	
	$dados = DB::queryRaw("
		SELECT 	num, escola, equipe, 
				velocidade,
				aceleracao,
				slalom,
				sus,
				tracao,	
				enduro,
				IF(total < 0, 0, total) as total,
				cla
				FROM (SELECT *,	IF(@anterior != total, @pos := @linha , @pos := @pos) as cla,
				@anterior:= total as v1, @linha:= @linha+1 as v2
				FROM (SELECT *,
				(seguranca + conforto + estaticas + enduro + dinamicas) as total".$consulta.") c18 ORDER BY total DESC) c19 WHERE num = $car");
		$valores = mysqli_fetch_all($dados,MYSQLI_BOTH)[0];}

