# Cases for the admin's configuration pages: provas, resultados, premiações,
# timers and tournaments, plus the judge's tournament entry page.
# Sourced by ../http-test.sh, which explains form_case and json_case.
#
# Each page is tried in the order a person would use it: create something,
# change it, keep and drop a named backup, then delete it. Deleting goes last
# because the earlier cases need the row to exist.

# --- Extra fixtures --------------------------------------------------------------
#
# A tournament prova, ZZT, with one match already drawn (car 1 against car 2,
# no winner yet), for torneio.php and tournament_entry.php. Inside event 28SU,
# so the runner's cleanup removes it with the event.
php_in_app '
    $p = new \Baja\Model\Prova();
    $p->setEventoId("28SU");
    $p->setProvaId("ZZT");
    $p->setNome("Torneio de teste");
    $p->setParams(json_decode("{\"type\": \"tournament\", \"inputs\": [], \"pontos\": {},
        \"status\": {\"frozen\": false, \"classification\": false},
        \"seeding\": {\"able\": {\"inputs\": [\"ZZA\"], \"formula\": \"1\"}, \"classification\": []},
        \"tournament\": {\"third_place_match\": false}}"));
    $p->save();
    $m = new \Baja\Model\Tournament();
    $m->setEventoId("28SU");
    $m->setProvaId("ZZT");
    $m->setMatchId(1);
    $m->setRound(0);
    $m->setEquipe1Id("1");
    $m->setEquipe2Id("2");
    $m->save();
' >/dev/null || bad "could not create the tournament fixture"

# The sound timer_tts.php would make, removed at the end (it is a file, not a row).
EXTRA_CLEANUP+='
    @unlink(\Baja\Model\Timer::soundsDir() . "/" . \Baja\Model\Timer::GENERATED_DIR . "/zzcsrf-teste.wav");
'

# Bits of PHP that print the state each form changes.
PROVA_ZZB='echo ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZB") ? "existe" : "nao existe";'
PROVA_ZZA_NOME='echo ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA")->getNome();'
PROVA_ZZA_BACKUP='echo str_contains((string) ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA")->getParamsBackup(), "zzbackup") ? "com backup" : "sem backup";'
PROVA_ZZA_MODIFICADO='$m = ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA")->getModificado(); echo $m ? "recalculada" : "nunca recalculada";'
# The prova's parameters as the forms post them back (unchanged).
PROVA_PARAMS='{"inputs": [{"code": "NOTA", "name": "Nota", "type": "number", "precision": 1}], "vars": {}, "pontos": {}}'

# --- prova.php -------------------------------------------------------------------

# "Nova Prova": creates prova ZZB.
form_case "prova.php: create (Salvar)" "$SID_ADM" \
    "/$EVT/prova.php?nova=true" "/$EVT/prova.php?nova=true" "$PROVA_ZZB" \
    "act=Salvar" "prova_id=ZZB" "nome=Prova criada pelo teste" "params={\"inputs\": []}"

# "Atualizar": renames prova ZZA (and saves its parameters).
form_case "prova.php: update (Atualizar)" "$SID_ADM" \
    "/$EVT/prova.php?id=ZZA" "/$EVT/prova.php?id=ZZA" "$PROVA_ZZA_NOME" \
    "act=Atualizar" "nome=Prova renomeada pelo teste" "params=$PROVA_PARAMS"

# The 💾 button: keeps the parameters as a backup named "zzbackup".
form_case "prova.php: save a named backup (💾)" "$SID_ADM" \
    "/$EVT/prova.php?id=ZZA" "/$EVT/prova.php?id=ZZA" "$PROVA_ZZA_BACKUP" \
    "act=💾" "params=$PROVA_PARAMS" "novoNomeadoNome=zzbackup"

# The ❌ button: drops that backup again.
form_case "prova.php: delete a named backup (❌)" "$SID_ADM" \
    "/$EVT/prova.php?id=ZZA" "/$EVT/prova.php?id=ZZA" "$PROVA_ZZA_BACKUP" \
    "act=❌" "nomeado=zzbackup"

# "Refresh Pontos": recomputes every team's points, which stamps the prova's
# "modificado" time. Emptied first, so the stamp shows whether it ran.
php_in_app 'ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA")->setModificado(null)->save();' >/dev/null
form_case "prova.php: recompute points (Refresh Pontos)" "$SID_ADM" \
    "/$EVT/prova.php?id=ZZA" "/$EVT/prova.php?id=ZZA" "$PROVA_ZZA_MODIFICADO" \
    "act=Refresh Pontos"

# "Deletar Prova": deletes ZZB, the one made above (ZZA is needed below).
form_case "prova.php: delete (Deletar Prova)" "$SID_ADM" \
    "/$EVT/prova.php?id=ZZB" "/$EVT/prova.php?id=ZZB" "$PROVA_ZZB" \
    "act=Deletar Prova"

# --- resultado.php ---------------------------------------------------------------

RES_ZZB='echo ResultadoQuery::create()->filterByEventoId("28SU")->findOneByResultadoId("28SU_ZZB") ? "existe" : "nao existe";'
RES_ZZA_NOME='echo ResultadoQuery::create()->filterByEventoId("28SU")->findOneByResultadoId("28SU_ZZA")->getNome();'
RES_ZZA_BACKUP='echo str_contains((string) ResultadoQuery::create()->filterByEventoId("28SU")->findOneByResultadoId("28SU_ZZA")->getColunasBackup(), "zzbackup") ? "com backup" : "sem backup";'
RES_COLUNAS='{"colunas": [{"header": "#", "val": "EquipeNum"}, {"header": "Nota", "val": "$NOTA"}]}'

# "Novo Resultado": creates resultado 28SU_ZZB.
form_case "resultado.php: create (Salvar)" "$SID_ADM" \
    "/$EVT/resultado.php?novo=true" "/$EVT/resultado.php?novo=true" "$RES_ZZB" \
    "act=Salvar" "resultado_id=28SU_ZZB" "nome=Resultado criado pelo teste" "inputs=ZZA" "colunas=$RES_COLUNAS"

# "Atualizar": renames 28SU_ZZA.
form_case "resultado.php: update (Atualizar)" "$SID_ADM" \
    "/$EVT/resultado.php?id=28SU_ZZA" "/$EVT/resultado.php?id=28SU_ZZA" "$RES_ZZA_NOME" \
    "act=Atualizar" "nome=Resultado renomeado pelo teste" "inputs=ZZA" "colunas=$RES_COLUNAS"

form_case "resultado.php: save a named backup (💾)" "$SID_ADM" \
    "/$EVT/resultado.php?id=28SU_ZZA" "/$EVT/resultado.php?id=28SU_ZZA" "$RES_ZZA_BACKUP" \
    "act=💾" "colunas=$RES_COLUNAS" "novoNomeadoNome=zzbackup"

form_case "resultado.php: delete a named backup (❌)" "$SID_ADM" \
    "/$EVT/resultado.php?id=28SU_ZZA" "/$EVT/resultado.php?id=28SU_ZZA" "$RES_ZZA_BACKUP" \
    "act=❌" "nomeado=zzbackup"

form_case "resultado.php: delete (Deletar Resultado)" "$SID_ADM" \
    "/$EVT/resultado.php?id=28SU_ZZB" "/$EVT/resultado.php?id=28SU_ZZB" "$RES_ZZB" \
    "act=Deletar Resultado"

# --- premiacao_admin.php -----------------------------------------------------------

PREM='echo \Baja\Model\PremiacaoQuery::create()->filterByEventoId("28SU")->findOneByPremiacaoId("ZZP") ? "existe" : "nao existe";'
PREM_NOME='echo \Baja\Model\PremiacaoQuery::create()->filterByEventoId("28SU")->findOneByPremiacaoId("ZZP")->getNome();'
PREM_BACKUP='echo str_contains((string) \Baja\Model\PremiacaoQuery::create()->filterByEventoId("28SU")->findOneByPremiacaoId("ZZP")->getCategoriasBackup(), "zzbackup") ? "com backup" : "sem backup";'

# "Nova Premiação": creates premiação ZZP, with no categories.
form_case "premiacao_admin.php: create (Salvar)" "$SID_ADM" \
    "/$EVT/premiacao_admin.php?nova=true" "/$EVT/premiacao_admin.php?nova=true" "$PREM" \
    "act=Salvar" "premiacao_id=ZZP" "nome=Premiacao de teste" "categorias=[]"

form_case "premiacao_admin.php: update (Atualizar)" "$SID_ADM" \
    "/$EVT/premiacao_admin.php?id=ZZP" "/$EVT/premiacao_admin.php?id=ZZP" "$PREM_NOME" \
    "act=Atualizar" "nome=Premiacao renomeada" "categorias=[]"

form_case "premiacao_admin.php: save a named backup (💾)" "$SID_ADM" \
    "/$EVT/premiacao_admin.php?id=ZZP" "/$EVT/premiacao_admin.php?id=ZZP" "$PREM_BACKUP" \
    "act=💾" "categorias=[]" "novoNomeadoNome=zzbackup"

form_case "premiacao_admin.php: delete a named backup (❌)" "$SID_ADM" \
    "/$EVT/premiacao_admin.php?id=ZZP" "/$EVT/premiacao_admin.php?id=ZZP" "$PREM_BACKUP" \
    "act=❌" "nomeado=zzbackup"

form_case "premiacao_admin.php: delete (Deletar Premiação)" "$SID_ADM" \
    "/$EVT/premiacao_admin.php?id=ZZP" "/$EVT/premiacao_admin.php?id=ZZP" "$PREM" \
    "act=Deletar Premiação"

# --- timer_admin.php ---------------------------------------------------------------

TIMER='$t = \Baja\Model\TimerQuery::create()->filterByEventoId("28SU")->findOneByTimerId(1);'
TIMER_EXISTE="$TIMER"' echo $t ? "existe" : "nao existe";'
TIMER_NOME="$TIMER"' echo $t->getNome();'
TIMER_BACKUP="$TIMER"' echo str_contains((string) $t->getConfigBackup(), "zzbackup") ? "com backup" : "sem backup";'
TIMER_CHAVE="$TIMER"' echo $t->getAccessKey();'
TIMER_CHAVE_LEITURA="$TIMER"' echo $t->getViewKey();'
TIMER_STATUS="$TIMER"' echo $t->getState()["status"] ?? "sem estado";'
TIMER_CONFIG='{"nome": "Timer de teste", "etapas": [{"nome": "Etapa", "tempo": 60}]}'

# "Novo Timer": creates timer 1.
form_case "timer_admin.php: create (Salvar)" "$SID_ADM" \
    "/$EVT/timer_admin.php?nova=true" "/$EVT/timer_admin.php?nova=true" "$TIMER_EXISTE" \
    "act=Salvar" "timer_id=1" "config=$TIMER_CONFIG"

# "Atualizar": a new config, here a new name.
form_case "timer_admin.php: update (Atualizar)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_NOME" \
    "act=Atualizar" 'config={"nome": "Timer renomeado", "etapas": [{"nome": "Etapa", "tempo": 60}]}'

form_case "timer_admin.php: save a named backup (💾)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_BACKUP" \
    "act=💾" "config=$TIMER_CONFIG" "novoNomeadoNome=zzbackup"

form_case "timer_admin.php: delete a named backup (❌)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_BACKUP" \
    "act=❌" "nomeado=zzbackup"

# "Gerar Nova Chave": a new access key; whoever had the old one is locked out.
form_case "timer_admin.php: new access key (Gerar Nova Chave)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_CHAVE" \
    "act=Gerar Nova Chave"

form_case "timer_admin.php: new view key (Gerar Nova Chave de Leitura)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_CHAVE_LEITURA" \
    "act=Gerar Nova Chave de Leitura"

# --- timer_action.php: the timer page's own endpoint ---------------------------------
#
# The timer page (timer.php) posts each button press as JSON. Opened with a
# session (?id=1) it must send the token in the X-CSRF-Token header; the
# token is on the page, in <meta name="csrf-token">. "Iniciar" (start) turns
# the timer's status from "stopped" (set by the update above) to "running".
# An action only replaces the stored state when it is newer, and the update
# above stamped it with the server's current millisecond: one second ahead,
# so this one is newer (the server accepts up to two seconds ahead).
AGORA_MS=$(( ($(date +%s) + 1) * 1000 ))
json_case "timer_action.php: an action with a session (iniciar)" "$SID_ADM" \
    "/$EVT/timer.php?id=1" "/$EVT/timer_action.php?id=1" "$TIMER_STATUS" \
    "{\"acoes\": [{\"acao\": \"iniciar\", \"id\": \"zzcsrf1\", \"at\": $AGORA_MS, \"state\": {\"status\": \"running\", \"step\": 0, \"started_at\": $AGORA_MS, \"remaining_ms\": 60000}}]}"

# Opened with the timer's access key (?k=) instead, the key is the
# credential: another site does not have it, so no token is asked for. A
# pause posted with the key and no token must work.
CHAVE=$(php_in_app "$TIMER_CHAVE")
code=$(post_json "" "/$EVT/timer_action.php?k=$CHAVE" "" \
    "{\"acoes\": [{\"acao\": \"pausar\", \"id\": \"zzcsrf2\", \"at\": $(( AGORA_MS + 1000 )), \"state\": {\"status\": \"paused\", \"step\": 0, \"started_at\": $AGORA_MS, \"remaining_ms\": 59000}}]}")
now=$(php_in_app "$TIMER_STATUS")
if [[ "$code" == 200 && "$now" == paused ]]; then ok "timer_action.php: with the access key, no token needed (paused)"
else bad "timer_action.php: with the access key (expected 200 and \"paused\", got $code and \"$now\")"; fi

# --- timer_tts.php: makes a sound file from a sentence, from timer_admin.php ---------
#
# Needs the stack's text-to-speech service; skipped when it is not configured.
if [[ -n "$(docker exec "$APP" printenv TTS_URL)" ]]; then
    form_case "timer_tts.php: make a sound" "$SID_ADM" \
        "/$EVT/timer_admin.php?id=1" "/$EVT/timer_tts.php" \
        'echo file_exists(\Baja\Model\Timer::soundsDir() . "/" . \Baja\Model\Timer::GENERATED_DIR . "/zzcsrf-teste.wav") ? "existe" : "nao existe";' \
        "texto=Teste" "nome=zzcsrf teste"
else
    skip "timer_tts.php: make a sound (TTS_URL is not set in $APP)"
fi

form_case "timer_admin.php: delete (Deletar Timer)" "$SID_ADM" \
    "/$EVT/timer_admin.php?id=1" "/$EVT/timer_admin.php?id=1" "$TIMER_EXISTE" \
    "act=Deletar Timer"

# --- tournament_entry.php: the judge picks a match's winner -------------------------
#
# The ✅ under a car and "Cancelar Resultado" used to be plain links (a GET),
# which any page could trigger with an <img src>. Now they are small forms.

MATCH_WINNER='$m = \Baja\Model\TournamentQuery::create()->filterByEventoId("28SU")->filterByProvaId("ZZT")->filterByMatchId(1)->findOne(); echo $m ? ($m->getWinner() ?? "sem vencedor") : "sem embate";'

form_case "tournament_entry.php: pick a winner (✅)" "$SID_ADM" \
    "/$EVT/tournament_entry.php?p=ZZT&round=0" "/$EVT/tournament_entry.php?p=ZZT&round=0" "$MATCH_WINNER" \
    "match=1" "setwinner=1"

form_case "tournament_entry.php: undo the winner (Cancelar Resultado)" "$SID_ADM" \
    "/$EVT/tournament_entry.php?p=ZZT&round=0" "/$EVT/tournament_entry.php?p=ZZT&round=0" "$MATCH_WINNER" \
    "reset=1"

# --- torneio.php: the admin draws the bracket -----------------------------------------

TORNEIO_FROZEN='echo ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZT")->getParams()->status->frozen ? "congelada" : "aberta";'
TORNEIO_EMBATES='echo \Baja\Model\TournamentQuery::create()->filterByEventoId("28SU")->filterByProvaId("ZZT")->count();'

# "Congelar Classificação": fixes the seeding order (car 1, then car 2).
form_case "torneio.php: freeze the seeding (Congelar Classificação)" "$SID_ADM" \
    "/$EVT/torneio.php?id=ZZT" "/$EVT/torneio.php?id=ZZT" "$TORNEIO_FROZEN" \
    "freeze=Congelar Classificação" "classification=1,2"

# "Destruir Chaveamento": deletes every match, here the fixture's one.
form_case "torneio.php: delete the bracket (Destruir Chaveamento)" "$SID_ADM" \
    "/$EVT/torneio.php?id=ZZT" "/$EVT/torneio.php?id=ZZT" "$TORNEIO_EMBATES" \
    "destroybracket=Destruir Chaveamento"

# "Criar Chaveamento": draws the matches from the frozen seeding.
form_case "torneio.php: draw the bracket (Criar Chaveamento)" "$SID_ADM" \
    "/$EVT/torneio.php?id=ZZT" "/$EVT/torneio.php?id=ZZT" "$TORNEIO_EMBATES" \
    "createbracket=Criar Chaveamento"

form_case "torneio.php: unfreeze the seeding (Descongelar Classificação)" "$SID_ADM" \
    "/$EVT/torneio.php?id=ZZT" "/$EVT/torneio.php?id=ZZT" "$TORNEIO_FROZEN" \
    "unfreeze=Descongelar Classificação"
