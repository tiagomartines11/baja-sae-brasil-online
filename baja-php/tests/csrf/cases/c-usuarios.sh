# shellcheck shell=bash
# Users and roles: the admin pages that create users and give or take their
# roles (BAJAAPP-76), and the bulk page for teams' queue codes. Read by ../http-test.sh, which defines form_case,
# php_in_app, the sessions ($SID_ADM ...) and $EVT (28SU).
#
# Each form_case reads: "who submits it, the page that shows the form, where
# the form posts, a bit of PHP printing what the form changes, and the
# fields". See the runner for the five things checked for each.
#
# Every user made here is called ZZCsrf..., so the runner's cleanup removes
# it whatever happens.

# A user's team queue codes (the one kind of legacy code still written),
# or "nao existe" when there is no such user.
perms_of() { # username
    echo 'echo ($u = UserQuery::create()->findOneByUsername("'"$1"'")) ? json_encode($u->getPermissions()) : "nao existe";'
}

# A user's active roles, "role@type:id", or "nao existe" when there is no
# such user. Since BAJAAPP-76 that is what the user pages change.
roles_of() { # username
    echo '$u = UserQuery::create()->findOneByUsername("'"$1"'"); if (!$u) { echo "nao existe"; return; }
          $s = \Propel\Runtime\Propel::getConnection()->prepare("SELECT CONCAT(r.code, \"@\", g.scope_type, \":\", g.scope_id) FROM user_role g JOIN role r ON r.role_id = g.role_id WHERE g.user_id = ? AND g.revoked_at IS NULL ORDER BY 1");
          $s->execute([$u->getUserId()]); echo json_encode($s->fetchAll(PDO::FETCH_COLUMN));'
}

# --- admin_usuarios.php: "Adicionar usuário" ----------------------------------
# Creates a user holding nothing yet. Before: ZZCsrfNovo does not exist.
# After a real submit: it exists, with no roles ("[]").
form_case "admin_usuarios.php: Adicionar usuário" "$SID_ADM" \
    "/$EVT/admin_usuarios.php" "/$EVT/admin_usuarios.php?act=create" \
    "$(roles_of ZZCsrfNovo)" \
    "username=ZZCsrfNovo"

# --- admin_users.php: "Papéis do usuário" -------------------------------------
# The per-event grid is read only since BAJAAPP-76; its form only creates
# the row, then opens the user's role page.
form_case "admin_users.php: Papéis do usuário" "$SID_ADM" \
    "/$EVT/admin_users.php" "/$EVT/admin_users.php?act=create" \
    "$(roles_of ZZCsrfGrade)" \
    "username=ZZCsrfGrade"

# A user for the role page below to change: holds nothing yet.
php_in_app '
    $u = new \Baja\Model\User();
    $u->setUsername("ZZCsrfAlvo");
    $u->save();
' >/dev/null
ALVO_ID=$(php_in_app 'echo UserQuery::create()->findOneByUsername("ZZCsrfAlvo")->getUserId();')

# --- admin_usuario.php: "Dar papel" -------------------------------------------
# Gives ZZCsrfAlvo the judge role on prova ZZA of 28SU, through RoleGranting.
# The end date is left to the RBAC core; 28SU must have a data_fim
# (fixture.sql) or the grant is refused and nothing changes.
form_case "admin_usuario.php: Dar papel" "$SID_ADM" \
    "/$EVT/admin_usuario.php?id=$ALVO_ID" "/$EVT/admin_usuario.php?id=$ALVO_ID&act=grant" \
    "$(roles_of ZZCsrfAlvo)" \
    "role=judge" "node=prova:$EVT/ZZA" "valid_until="

# --- admin_usuario.php: "Remover" ---------------------------------------------
# Takes that grant away again.
GRANT_ID=$(php_in_app '$s = \Propel\Runtime\Propel::getConnection()->prepare("SELECT grant_id FROM user_role WHERE user_id = ? AND revoked_at IS NULL"); $s->execute(['"$ALVO_ID"']); echo $s->fetchColumn();')
form_case "admin_usuario.php: Remover" "$SID_ADM" \
    "/$EVT/admin_usuario.php?id=$ALVO_ID" "/$EVT/admin_usuario.php?id=$ALVO_ID&act=revoke" \
    "$(roles_of ZZCsrfAlvo)" \
    "grant_id=$GRANT_ID"

# --- admin_fila_acesso.php: "Conceder acessos" --------------------------------
# One pasted line, "ZZCsrfFila, 7": the queue (fila) 1 of 28SU for team 7.
# The page creates the user when it does not exist.
form_case "admin_fila_acesso.php: Conceder acessos" "$SID_ADM" \
    "/$EVT/admin_fila_acesso.php" "/$EVT/admin_fila_acesso.php?act=grant" \
    "$(perms_of ZZCsrfFila)" \
    "evento=$EVT" "fila=1" "lista=ZZCsrfFila, 7"

# --- admin_fila_acesso.php: "Remover acessos" ---------------------------------
# Takes fila 1 of 28SU away from every user. ZZCsrfFila had only that, so a
# real submit leaves it with nothing ("[]"); the row itself stays since
# BAJAAPP-76. Only 28SU codes match, so no real user of the dev database is
# touched.
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
