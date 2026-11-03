# shellcheck shell=bash
# The program admin page (BAJAAPP-90): creating and editing a program.
# Read by ../http-test.sh, which defines form_case, php_in_app and the
# sessions ($SID_ADM ...). ZZCsrfAdmin holds platform_admin, which carries
# programa.manage.
#
# The program made here, ZZCSRF_P, is removed at the end of the run with
# everything else, and now, in case an interrupted run left it. Its
# role_audit rows stay: MySQL refuses to delete them, like every other
# test's.

EXTRA_CLEANUP="$EXTRA_CLEANUP"'
    \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM programa WHERE programa_id = \"ZZCSRF_P\"");
'
php_in_app '
    \Propel\Runtime\Propel::getConnection()->exec("DELETE FROM programa WHERE programa_id = \"ZZCSRF_P\"");
' >/dev/null

# A program's row, or "nao existe".
programa_de() { # code
    echo '$s = \Propel\Runtime\Propel::getConnection()->prepare("SELECT CONCAT(nome, \"|\", COALESCE(parent_id, \"\"), \"|\", ordem, \"|\", ativo) FROM programa WHERE programa_id = ?");
          $s->execute(["'"$1"'"]); $v = $s->fetchAll(PDO::FETCH_COLUMN); echo $v === [] ? "nao existe" : $v[0];'
}

# --- admin_programas.php: "Salvar" (new program) ------------------------------
# Before: ZZCSRF_P does not exist. After a real submit: it exists, at the top.
form_case "admin_programas.php: Salvar (novo programa)" "$SID_ADM" \
    "/$EVT/admin_programas.php?novo=true" "/$EVT/admin_programas.php?novo=true&act=create" \
    "$(programa_de ZZCSRF_P)" \
    "programa_id=ZZCSRF_P" "nome=Programa CSRF" "parent_id=" "ordem=99"

# --- admin_programas.php: "Atualizar" -------------------------------------------
# Renames it and makes it inactive. The parent stays, so no confirmation step.
form_case "admin_programas.php: Atualizar" "$SID_ADM" \
    "/$EVT/admin_programas.php?id=ZZCSRF_P" "/$EVT/admin_programas.php?id=ZZCSRF_P&act=update" \
    "$(programa_de ZZCSRF_P)" \
    "nome=Programa CSRF renomeado" "parent_id=" "ordem=98"
