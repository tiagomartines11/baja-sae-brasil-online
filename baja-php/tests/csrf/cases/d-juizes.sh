# Cases for the judges' own forms: typing a team's score on entry.php (saved
# by save_entry.php) and the one-line-per-attempt form on rolling_entry.php
# (saved by save_rolling_entry.php). Read by ../http-test.sh, which explains
# form_case and the five steps each case goes through.
#
# These are the forms used most during an event, by every judge, so they are
# the ones a forged request would most likely target: a page on another site
# could otherwise post a made-up score for any team, with the judge's name in
# the log.

# --- Setup -------------------------------------------------------------------
#
# The fixture's prova ZZA has one number field, NOTA. For the rolling form we
# add a second prova, ZZR, with one number field, T. rolling_entry.php only
# lists teams marked present, so team 1 is marked present. All of it belongs
# to the 28SU event, which the runner deletes at the end.
php_in_app '
    $p = new \Baja\Model\Prova();
    $p->setEventoId("28SU");
    $p->setProvaId("ZZR");
    $p->setNome("Prova rolante de teste");
    $p->setStatus("Parcial");
    $p->setParams(json_decode("{\"inputs\": [{\"code\": \"T\", \"name\": \"Tempo\", \"type\": \"number\", \"precision\": 1}], \"vars\": {}, \"pontos\": {}}"));
    $p->setTotals(json_decode("{}"));
    $p->save();
    $e = EquipeQuery::create()->filterByEventoId("28SU")->findOneByEquipeId(1);
    $e->setPresente(true);
    $e->save();
' >/dev/null || bad "d-juizes: could not create prova ZZR"

# What the database holds for team 1 on prova ZZA: its NOTA, or "sem nota"
# when the team has no row at all (nothing saved yet, or the row was deleted).
NOTA_EQUIPE_1='
    $i = InputQuery::create()->filterByEventoId("28SU")->filterByProvaId("ZZA")->findOneByEquipeId(1);
    echo $i ? json_encode($i->getDados()->NOTA ?? null) : "sem nota";
'

# 1. A judge saves a score of 7.5 for team 1.
#    Before: "sem nota". Without the token, with another session's token, or
#    as a GET it must stay "sem nota"; with the token it becomes 7.5.
form_case "entry.php -> save_entry.php: Salvar" \
    "$SID_JUI" \
    "/$EVT/entry.php?p=ZZA&t=1" \
    "/$EVT/save_entry.php?p=ZZA&t=1" \
    "$NOTA_EQUIPE_1" \
    "NOTA=7.5" "submit=Salvar"

# 2. The same judge deletes that score ("Deletar Nota" on the same page).
#    Before: 7.5 (saved by case 1). Every forged attempt must leave 7.5;
#    with the token the row is gone: "sem nota".
form_case "entry.php -> save_entry.php: Deletar Nota" \
    "$SID_JUI" \
    "/$EVT/entry.php?p=ZZA&t=1" \
    "/$EVT/save_entry.php?p=ZZA&t=1" \
    "$NOTA_EQUIPE_1" \
    "delete=Deletar Nota"

# 3. One attempt of team 1 on the rolling form. Each save adds a line to the
#    team's list of attempts, so the state is how many lines it has: 0 before,
#    still 0 after every forged attempt, 1 after the real one. The admin
#    submits it, because the judge user only judges ZZA.
form_case "rolling_entry.php -> save_rolling_entry.php: Salvar" \
    "$SID_ADM" \
    "/$EVT/rolling_entry.php?p=ZZR" \
    "/$EVT/save_rolling_entry.php?p=ZZR" \
    '$i = InputQuery::create()->filterByEventoId("28SU")->filterByProvaId("ZZR")->findOneByEquipeId(1);
     echo $i ? count((array)($i->getDados()->entries ?? [])) : 0;' \
    "team=1" "T=12.5" "submit=Salvar"
