# Cases for the event, team and queue admin forms (BAJAAPP-41).
# Read by ../http-test.sh, which explains form_case and the five checks each
# case makes. Every request here is sent as the admin, ZZCsrfAdmin.
#
# The cases run in the order written. Two things to keep in mind when
# reading them:
#
#  - A delete case needs something to delete. The fixture's own event, 28SU,
#    and its two teams are shared with the other case files, so the deletes
#    here work on rows made for them: team 9, event 28NE (made by the create
#    case just before), queue 7 (same).
#  - The shared 28SU rows that are changed (its title, prova ZZA's status,
#    team 1's name) are put back at the end, so the next file sees the
#    fixture as it was loaded.

# Rows made here that the 28SU cleanup does not cover: the event made by the
# create case and the one made by the clone case. Removed at the end of the
# run with everything else, and now, in case an interrupted run left them.
EXTRA_CLEANUP="$EXTRA_CLEANUP"'
    \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM evento WHERE evento_id IN (\"28NE\", \"28SE\")");
'
php_in_app '
    \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM evento WHERE evento_id IN (\"28NE\", \"28SE\")");
    // Team 9, only for the delete case below.
    $e = new \Baja\Model\Equipe();
    $e->setEventoId("28SU"); $e->setEquipeId(9);
    $e->setEquipe("EQUIPE PARA APAGAR"); $e->setEscola("ESCOLA"); $e->setEstado("SP");
    $e->save();
' >/dev/null

# --- evento.php ---------------------------------------------------------------

# Editing an event. The state is its title and its spoiler flag: the form
# sends a new title and ticks "Spoilers?", so "Fixture BAJAAPP-41|0" should
# become "ZZ Atualizado|1", and only with the token.
form_case "evento.php: Atualizar (with spoilers)" "$SID_ADM" \
    "/$EVT/evento.php?id=28SU" "/$EVT/evento.php?id=28SU" \
    '$e = EventoQuery::create()->findPk("28SU"); echo $e->getTitulo() . "|" . (int) $e->getSpoilers();' \
    "act=Atualizar" "titulo=ZZ Atualizado" "nome=Evento de teste (CSRF)" "programa=Sul" "ano=2028" \
    "carga_horaria=10" "spoilers=1" "data=" "local=" "presidente=" "mandato=" "menu=[]"

# Creating an event, 28NE. The state is whether 28NE exists: 0, then 1.
form_case "evento.php: Salvar (new event)" "$SID_ADM" \
    "/$EVT/evento.php?novo=true" "/$EVT/evento.php?novo=true" \
    'echo EventoQuery::create()->findPk("28NE") ? 1 : 0;' \
    "act=Salvar" "evento_id=28NE" "titulo=ZZ Novo" "nome=Evento novo (CSRF)" "programa=Sul" "ano=2028" \
    "carga_horaria=10" "data=" "local=" "presidente=" "mandato=" "menu=[]"

# Deleting the event just made. The state is whether 28NE exists: 1, then 0.
# The GET check matters most here: as a plain link, this used to delete.
form_case "evento.php: Deletar Evento" "$SID_ADM" \
    "/$EVT/evento.php?id=28NE" "/$EVT/evento.php?id=28NE" \
    'echo EventoQuery::create()->findPk("28NE") ? 1 : 0;' \
    "act=Deletar Evento"

# --- admin_provas.php -----------------------------------------------------------

# The spoiler toggle of the event in the URL (28SU). The case above left it
# on, so the toggle turns it off: 1, then 0.
form_case "admin_provas.php: spoiler toggle" "$SID_ADM" \
    "/$EVT/admin_provas.php" "/$EVT/admin_provas.php?act=spoilers" \
    'echo (int) EventoQuery::create()->findPk("28SU")->getSpoilers();' \
    "submit=Toggle"

# A prova's status, Parcial or Final, flipped from the list of provas. It was
# a link (admin_provas.php?act=chstatus&prova=ZZA) and is now a one-button
# form. The state is ZZA's status; it should flip once, with the token.
form_case "admin_provas.php: status toggle" "$SID_ADM" \
    "/$EVT/admin_provas.php" "/$EVT/admin_provas.php" \
    'echo ProvaQuery::create()->filterByEventoId("28SU")->findOneByProvaId("ZZA")->getStatus();' \
    "act=chstatus" "prova=ZZA"

# --- equipe.php ------------------------------------------------------------------

# Creating team 3. The state is whether it exists: 0, then 1.
form_case "equipe.php: Salvar (new team)" "$SID_ADM" \
    "/$EVT/equipe.php?nova=true" "/$EVT/equipe.php?nova=true" \
    'echo EquipeQuery::create()->filterByEventoId("28SU")->findOneByEquipeId(3) ? 1 : 0;' \
    "act=Salvar" "equipe_id=3" "equipe=ZZ NOVA" "equipe_curto=ZZ" "escola=ESCOLA" \
    "escola_curto=ESC" "cidade=CIDADE" "estado=SP" "presente=1"

# Editing team 1. The state is its name: "EQUIPE TESTE 1", then "ZZ MUDOU".
form_case "equipe.php: Atualizar" "$SID_ADM" \
    "/$EVT/equipe.php?id=1" "/$EVT/equipe.php?id=1" \
    'echo EquipeQuery::create()->filterByEventoId("28SU")->findOneByEquipeId(1)->getEquipe();' \
    "act=Atualizar" "equipe=ZZ MUDOU" "equipe_curto=ZZ" "escola=ESCOLA DE TESTE UM" \
    "escola_curto=ESC" "cidade=CIDADE" "estado=SP" "presente=1"

# Deleting team 9, made at the top of this file. The state is whether it
# exists: 1, then 0.
form_case "equipe.php: Deletar Equipe" "$SID_ADM" \
    "/$EVT/equipe.php?id=9" "/$EVT/equipe.php?id=9" \
    'echo EquipeQuery::create()->filterByEventoId("28SU")->findOneByEquipeId(9) ? 1 : 0;' \
    "act=Deletar Equipe"

# --- fila.php --------------------------------------------------------------------

# Creating queue 7 in 28SU. The state is whether it exists: 0, then 1.
form_case "fila.php: Salvar (new queue)" "$SID_ADM" \
    "/$EVT/fila.php?nova=true" "/$EVT/fila.php?nova=true" \
    'echo \Baja\Model\FilaQuery::create()->filterByEventoId("28SU")->findOneByFilaId(7) ? 1 : 0;' \
    "act=Salvar" "evento_id=28SU" "fila_id=7" "nome=ZZ Fila" "tempo_espera=0" "novo_status=fechada"

# Renaming queue 7. The state is its name: "ZZ Fila", then "ZZ Fila Mudou".
form_case "fila.php: Atualizar" "$SID_ADM" \
    "/$EVT/fila.php?evento=28SU&id=7" "/$EVT/fila.php?evento=28SU&id=7" \
    'echo \Baja\Model\FilaQuery::create()->filterByEventoId("28SU")->findOneByFilaId(7)->getNome();' \
    "act=Atualizar" "nome=ZZ Fila Mudou" "tempo_espera=0"

# Deleting queue 7. The state is whether it exists: 1, then 0.
form_case "fila.php: Deletar Fila" "$SID_ADM" \
    "/$EVT/fila.php?evento=28SU&id=7" "/$EVT/fila.php?evento=28SU&id=7" \
    'echo \Baja\Model\FilaQuery::create()->filterByEventoId("28SU")->findOneByFilaId(7) ? 1 : 0;' \
    "act=Deletar Fila"

# --- clonar_evento.php -------------------------------------------------------------

# Cloning 28SU, with its provas, into a new event 28SE. The state is whether
# 28SE exists: 0, then 1. As a GET the page only shows the form.
form_case "clonar_evento.php: Clonar" "$SID_ADM" \
    "/$EVT/clonar_evento.php" "/$EVT/clonar_evento.php" \
    'echo EventoQuery::create()->findPk("28SE") ? 1 : 0;' \
    "act=Clonar" "ref_evento_id=28SU" "novo_evento_id=28SE" "clone_provas=1"

# --- put the shared rows back -----------------------------------------------------
php_in_app '
    $e = EventoQuery::create()->findPk("28SU");
    $e->setTitulo("Fixture BAJAAPP-41"); $e->setSpoilers(false); $e->save();
    \Propel\Runtime\Propel::getConnection()->exec("UPDATE prova SET status = 1 WHERE evento_id = \"28SU\" AND prova_id = \"ZZA\"");
    $q = EquipeQuery::create()->filterByEventoId("28SU")->findOneByEquipeId(1);
    $q->setEquipe("EQUIPE TESTE 1"); $q->save();
    EquipeQuery::create()->filterByEventoId("28SU")->filterByEquipeId(3)->delete();
' >/dev/null
