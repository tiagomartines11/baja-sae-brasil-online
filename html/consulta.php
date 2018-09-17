<?php

if($pg == "seg"){
	$dados = DB::queryRaw("SELECT * , IF(seg_nota IS NULL, 0, seg_nota) + IF(doc_nota IS NULL, 0, doc_nota) as nota
							
							FROM equipes
							INNER JOIN ".$pg." ON num = ".$pg."_num
							ORDER BY ".$pg."_num");
}elseif($pg == "fre"){
    $dados = DB::queryRaw("SELECT * 
							FROM equipes
							INNER JOIN ".$pg." ON num = ".$pg."_num
							ORDER BY ".$pg."_num");
}elseif($pg == "gru"){
$dados = DB::queryRaw("SELECT * 
							FROM equipes
							INNER JOIN ".$pg." ON num = ".$pg."_num
							ORDER BY ".$pg."_num");
}elseif($pg == "end"){

    $dados = DB::queryRaw("SELECT * , TIME_FORMAT(SEC_TO_TIME(end_melhor), '%H:%i:%s') as tempo, end_voltas as resultado
							FROM equipes
							INNER JOIN end ON num = end_num
							ORDER BY end_voltas DESC");
}elseif($pg == "endc"){

    $dados = DB::queryRaw("SELECT * FROM (SELECT end_num, escola, equipe, end_timestamp, FROM_UNIXTIME(end_timestamp - @mints, \"%H:%i:%s\") as momento, IF(@last!= end_num, @runtot:=0, @runtot:=1+@runtot) as volta, IF(@last!= end_num, \"--\", TIME_FORMAT(SEC_TO_TIME(end_timestamp - @lastts), '%H:%i:%s')) as duracao,  @last:= end_num, @lastts:=end_timestamp
							FROM (SELECT @runtot:=0, @last:=0, @lastts:=0) c, (SELECT @mints := (SELECT min(end_timestamp) FROM endc)) d, endc
							INNER JOIN equipes ON num = end_num
							ORDER BY end_num, end_timestamp ASC) t ORDER BY end_timestamp ASC")
    ;
}elseif($pg == "con"){
	
	$dados = DB::queryRaw("SELECT * , con_nota as resultado
							FROM equipes
							INNER JOIN con ON num = con_num
							ORDER BY con_nota DESC");
}elseif($pg == "vel"){

    if($pg == "vel"){$ordem = "DESC"; $sinal = ">";}
    else{$ordem = "ASC"; $sinal = "<";}

    $dados = DB::queryRaw("SELECT equipes.*, avf_num, ROUND(".$pg."_1, 2), ROUND(".$pg."_2, 2), avf_user, avf_modificado, IF(".$pg."_2 IS NULL,".$pg."_1,IF(".$pg."_1 ".$sinal." ".$pg."_2, ".$pg."_1 ,".$pg."_2 )) as resultado 
							FROM equipes
							INNER JOIN avf ON num = avf_num
							ORDER BY resultado ".$ordem."");
}
elseif($pg == "ace"){

    if($pg == "vel"){$ordem = "DESC"; $sinal = ">";}
    else{$ordem = "ASC"; $sinal = "<";}

    $dados = DB::queryRaw("SELECT equipes.*, avf_num, ".$pg."_1, ".$pg."_2, avf_user, avf_modificado, IF(".$pg."_2 IS NULL,".$pg."_1,IF(".$pg."_1 ".$sinal." ".$pg."_2, ".$pg."_1 ,".$pg."_2 )) as resultado 
							FROM equipes
							INNER JOIN avf ON num = avf_num
							ORDER BY resultado ".$ordem."");
}elseif($pg == "sla"){
	$dados = DB::queryRaw("SELECT *, IF(tempC2 IS NULL, tempC1,IF(tempC1 < tempC2, tempC1, tempC2)) as resultado 
							FROM (SELECT  *, 
										(sla_1_temp + 4*sla_1_con + 10*sla_1_gat + 2*sla_1_bol) as tempC1, 
										(sla_2_temp + 4*sla_2_con + 10*sla_2_gat + 2*sla_2_bol) as tempC2,
										CONCAT(
											sla_1_temp,' ',
											IF(sla_1_con >0 OR sla_1_gat >0 OR sla_1_bol >0,' (',''), 
											IF(sla_1_bol >0,CONCAT(sla_1_bol, '<img width= 12px src=\'img/bolinha.png\'>'),''),
											IF((sla_1_bol >0 AND sla_1_con >0) OR (sla_1_bol >0 AND sla_1_gat >0),' / ',''), 
											IF(sla_1_con >0,CONCAT(sla_1_con, '<img width= 12px src=\'img/cone.png\'>'),''),
											IF(sla_1_con >0 AND sla_1_gat >0,' / ',''),
											IF(sla_1_gat >0,CONCAT(sla_1_gat, '<img width= 12px src=\'img/gate.png\'>'),''),											
											IF(sla_1_con >0 OR sla_1_gat >0  OR sla_1_bol >0,')','')
										) as temp1,
										CONCAT(
											sla_2_temp,' ',
											IF(sla_2_con >0 OR sla_2_gat >0 OR sla_2_bol >0,' (',''), 
											IF(sla_2_bol >0,CONCAT(sla_2_bol, '<img width= 12px src=\'img/bolinha.png\'>'),''),
											IF((sla_2_bol >0 AND sla_2_con >0) OR (sla_2_bol >0 AND sla_2_gat >0),' / ',''), 
											IF(sla_2_con >0,CONCAT(sla_2_con, '<img width= 12px src=\'img/cone.png\'>'),''),
											IF(sla_2_con >0 AND sla_2_gat >0,' / ',''),
											IF(sla_2_gat >0,CONCAT(sla_2_gat, '<img width= 12px src=\'img/gate.png\'>'),''),											
											IF(sla_2_con >0 OR sla_2_gat >0  OR sla_2_bol >0,')','')
										) as temp2											
										FROM equipes 
										INNER JOIN sla ON num = sla_num) c
							ORDER BY resultado ASC");
}elseif($pg == "tra"){
	//6 casos de reultados para tra e sus
	//   1ª passada   |   2ª passada   |   melhorTemp   |   melhorDist
	//-------------------------------------------------------------------
	//      Tempo     |     Tempo      |    NOT NULL     |     NULL
	//    Distância   |   Distância    |      NULL       |   NOT NULL
	//      Tempo     |   Distância    |    NOT NULL     |   NOT NULL
	//    Distância   |     Tempo      |    NOT NULL     |   NOT NULL
	//      Tempo     |       X        |    NOT NULL     |     NULL
	//    Distância   |       X        |      NULL       |   NOT NULL
	$dados = DB::queryRaw("SELECT *, 
								IF(melhorTemp IS NOT NULL, melhorTemp, 1000000) as ordem,
								IF(melhorTemp IS NOT NULL, melhorTemp, melhorDist) as resultado									
							FROM (SELECT *, 
								IF(tra_1_temp IS NOT NULL AND tra_2_temp IS NOT NULL,
										IF(tra_1_temp > tra_2_temp, tra_2_temp, tra_1_temp),
											IF(tra_1_temp IS NULL, tra_2_temp, tra_1_temp)) as melhorTemp,
								IF(tra_1_dist < tra_2_dist, tra_2_dist, tra_1_dist) as melhorDist
							FROM equipes 
							INNER JOIN tra ON num = tra_num) c
							ORDER BY ordem ASC, melhorDist DESC");
}elseif($pg == "sus"){
		$dados = DB::queryRaw("SELECT *, 
								CONCAT(
									sus_1_temp,' ',
									IF(sus_1_con >0 OR sus_1_gat >0,' (',''), 
									IF(sus_1_con >0,CONCAT(sus_1_con, '<img width= 12px src=\'img/cone.png\'>'),''),
									IF(sus_1_con >0 AND sus_1_gat >0,' / ',''),
									IF(sus_1_gat >0,CONCAT(sus_1_gat, '<img width= 12px src=\'img/gate.png\'>'),''),											
									IF(sus_1_con >0 OR sus_1_gat >0,')','')
								) as temp1,
								CONCAT(
								sus_2_temp,' ',
									IF(sus_2_con >0 OR sus_2_gat >0,' (',''), 
									IF(sus_2_con >0,CONCAT(sus_2_con, '<img width= 12px src=\'img/cone.png\'>'),''),
									IF(sus_2_con >0 AND sus_2_gat >0,' / ',''),
									IF(sus_2_gat >0,CONCAT(sus_2_gat, '<img width= 12px src=\'img/gate.png\'>'),''),											
									IF(sus_2_con >0 OR sus_2_gat >0,')','')
								) as temp2,							
								IF(melhorTemp IS NOT NULL, melhorTemp, 1000000) as ordem,
								IF(melhorTemp IS NOT NULL, melhorTemp, melhorDist) as resultado									
							FROM (SELECT *, 
								IF(sus_1_temp IS NOT NULL AND sus_2_temp IS NOT NULL, 
									IF(sus_1_temp > sus_2_temp, sus_2_temp, sus_1_temp), 
										IF(sus_1_temp IS NULL, sus_2_temp, sus_1_temp)) as melhorTemp,
							IF(sus_1_dist < sus_2_dist, sus_2_dist, sus_1_dist) as melhorDist
							FROM (SELECT *,
								(4*sus_1_con + 8*sus_1_gat) as pen1,
								(4*sus_2_con + 8*sus_2_gat) as pen2 
							FROM equipes 
							INNER JOIN sus ON num = sus_num) c) a
							ORDER BY ordem ASC, melhorDist DESC
							");

}elseif($pg == "fin" || $pg == "apr"){
	if($pg == "apr"){$tot = _ETAPA == "NAC" ? 150 : 250;}
	if($pg == "fin"){$tot = 30;}	
	$dados = DB::queryRaw("SELECT * , IF(".$pg."_pres = 1, ROUND((".$pg."_pw + ".$pg."_ele +".$pg."_fre +".$pg."_des +".$pg."_sus +".$pg."_mkt +".$pg."_cal +".$pg."_ges+0.00001)*$tot/80, 2), ROUND((".$pg."_pw + ".$pg."_ele +".$pg."_fre +".$pg."_des +".$pg."_sus +".$pg."_mkt +".$pg."_cal +".$pg."_ges+0.00001)*$tot*0, 2)) as resultado
							FROM equipes
							INNER JOIN ".$pg." ON num = ".$pg."_num
							ORDER BY resultado DESC");
}elseif($pg == "rel"){
	
	$dados = DB::queryRaw("SELECT * , ROUND(IF((rel_nota + rel_pen) < 0, 0, (rel_nota + rel_pen)), 2) as resultado, ROUND(rel_nota, 2) as n1
							FROM equipes
							INNER JOIN rel ON num = rel_num
							ORDER BY resultado DESC");
}

if (!$dados || $dados->num_rows <= 0) {
	if ($prova) $prova->setStatus(null);
}else{
	$valores = mysqli_fetch_all($dados,MYSQLI_BOTH);
}

	 		