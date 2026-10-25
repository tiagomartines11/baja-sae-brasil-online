# shellcheck shell=bash
# Users and permissions: the admin pages that create users, change what they
# may do, or delete them. Read by ../http-test.sh, which defines form_case,
# php_in_app, the sessions ($SID_ADM ...) and $EVT (28SU).
#
# Each form_case reads: "who submits it, the page that shows the form, where
# the form posts, a bit of PHP printing what the form changes, and the
# fields". See the runner for the five things checked for each.
#
# Every user made here is called ZZCsrf..., so the runner's cleanup removes
# it whatever happens.

# A user's permissions, or "nao existe" when there is no such user. This is
# the state every case below looks at.
perms_of() { # username
    echo 'echo ($u = UserQuery::create()->findOneByUsername("'"$1"'")) ? json_encode($u->getPermissions()) : "nao existe";'
}

# --- admin_usuarios.php: "Adicionar usuário" ----------------------------------
# Creates a user with no permissions yet. Before: ZZCsrfNovo does not exist.
# After a real submit: it exists.
form_case "admin_usuarios.php: Adicionar usuário" "$SID_ADM" \
    "/$EVT/admin_usuarios.php" "/$EVT/admin_usuarios.php?act=create" \
    "$(perms_of ZZCsrfNovo)" \
    "username=ZZCsrfNovo"

# --- admin_users.php: "Atualizar Usuário" -------------------------------------
# The per-event grid: makes ZZCsrfGrade a judge of prova ZZA. The hidden
# j[]=index is what the real form always sends.
form_case "admin_users.php: Atualizar Usuário" "$SID_ADM" \
    "/$EVT/admin_users.php" "/$EVT/admin_users.php?act=create" \
    "$(perms_of ZZCsrfGrade)" \
    "username=ZZCsrfGrade" "j[]=index" "j[]=${EVT}_ZZA"

# A user for the two edit pages below to change. Its own, so that removing
# its permissions cannot take away a session another group's cases log in
# with.
php_in_app '
    $u = new \Baja\Model\User();
    $u->setUsername("ZZCsrfAlvo");
    $u->setPermissions(["index", "'"$EVT"'_ZZA"]);
    $u->save();
' >/dev/null
ALVO_ID=$(php_in_app 'echo UserQuery::create()->findOneByUsername("ZZCsrfAlvo")->getUserId();')

# --- user.php: "Salvar" -------------------------------------------------------
# The current event's provas plus the admin flag. Ticking admin (and
# unticking ZZA) changes ZZCsrfAlvo from ["index","28SU_ZZA"].
form_case "user.php: Salvar" "$SID_ADM" \
    "/$EVT/user.php?id=$ALVO_ID" "/$EVT/user.php?id=$ALVO_ID&act=save" \
    "$(perms_of ZZCsrfAlvo)" \
    "j[]=index" "j[]=admin"

# --- admin_usuario.php: "Salvar" ----------------------------------------------
# The full permission editor: replaces the whole list, here with
# index + certificados, which is neither what it had nor what user.php left.
form_case "admin_usuario.php: Salvar" "$SID_ADM" \
    "/$EVT/admin_usuario.php?id=$ALVO_ID" "/$EVT/admin_usuario.php?id=$ALVO_ID&act=save" \
    "$(perms_of ZZCsrfAlvo)" \
    "j[]=index" "j[]=certificados"

# --- admin_fila_acesso.php: "Conceder acessos" --------------------------------
# One pasted line, "ZZCsrfFila, 7": the queue (fila) 1 of 28SU for team 7.
# The page creates the user when it does not exist.
form_case "admin_fila_acesso.php: Conceder acessos" "$SID_ADM" \
    "/$EVT/admin_fila_acesso.php" "/$EVT/admin_fila_acesso.php?act=grant" \
    "$(perms_of ZZCsrfFila)" \
    "evento=$EVT" "fila=1" "lista=ZZCsrfFila, 7"

# --- admin_fila_acesso.php: "Remover acessos" ---------------------------------
# Takes fila 1 of 28SU away from every user. ZZCsrfFila had only that, so a
# real submit deletes it ("nao existe"). Only 28SU permissions match, so no
# real user of the dev database is touched.
form_case "admin_fila_acesso.php: Remover acessos" "$SID_ADM" \
    "/$EVT/admin_fila_acesso.php" "/$EVT/admin_fila_acesso.php?act=revoke" \
    "$(perms_of ZZCsrfFila)" \
    "rm_evento=$EVT" "rm_fila=1"

# --- admin_push.php: "Enviar Notificação" -------------------------------------
# Sending a push reaches real phones through OneSignal, and the database
# keeps no trace of it, so form_case cannot be used. Instead:
#
#   - without a token, and with another session's token: 403;
#   - with the token, but an empty title: the page answers 200 with its own
#     "Título e mensagem são obrigatórios" message. That message comes after
#     the token check and before any sending, so seeing it proves the token
#     was accepted, and nothing is sent.
#
# What this does not check: that a GET changes nothing. Nothing would show
# it; the page reads the token only from the POST body, like every other.
push_body() { # token
    local data=(--data-urlencode "title=" --data-urlencode "message=")
    [[ -n "$1" ]] && data+=(--data-urlencode "_csrf=$1")
    curl -s "${CURL_ARGS[@]}" -b "$(cookies "$SID_ADM")" -w '\n%{http_code}' "${data[@]}" "$BASE/$EVT/admin_push.php?act=send"
}
PUSH_TOKEN=$(token_on_page "$SID_ADM" "/$EVT/admin_push.php")
if [[ -n "$PUSH_TOKEN" ]]; then ok "admin_push.php: the form carries a token"; else bad "admin_push.php: the form shows no token"; fi

resp=$(push_body "")
if [[ "$(tail -1 <<< "$resp")" == 403 ]]; then ok "admin_push.php: no token: 403"; else bad "admin_push.php: no token (expected 403, got $(tail -1 <<< "$resp"))"; fi

resp=$(push_body "$(token_on_page "$SID_OUT" "/$EVT/admin_push.php")")
if [[ "$(tail -1 <<< "$resp")" == 403 ]]; then ok "admin_push.php: another session's token: 403"; else bad "admin_push.php: another session's token (expected 403, got $(tail -1 <<< "$resp"))"; fi

resp=$(push_body "$PUSH_TOKEN")
if [[ "$(tail -1 <<< "$resp")" == 200 ]] && grep -qF "obrigatórios" <<< "$resp"; then ok "admin_push.php: with the token: past the check (empty title refused, nothing sent)"
else bad "admin_push.php: with the token (expected 200 and the empty-title message, got $(tail -1 <<< "$resp"))"; fi
