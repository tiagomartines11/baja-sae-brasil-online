#!/usr/bin/env bash
# HTTP-level tests for the certificate insertion pages.
#
# These cover the things that only exist once nginx, PHP and the session shim
# are all in the path: who is turned away, how, and that a state-changing POST
# without a CSRF token changes nothing. The PHP suite
# (tests/certificado/run.php) covers the validation rules themselves.
#
# Run from the host against a running stack:
#   baja-php/tests/certificado/insercao-http-test.sh [base-url]
#
# The base URL must be the juiz vhost's own hostname, not 127.0.0.1 — nginx
# picks the server block by Host. If it is not in /etc/hosts, point curl at
# the stack with CURL_EXTRA instead of editing hosts:
#
#   CURL_EXTRA="--resolve juiz.baja.local:80:127.0.0.1" \
#       baja-php/tests/certificado/insercao-http-test.sh http://juiz.baja.local
#
# Sessions are seeded straight into the Redis session cache rather than by
# logging in, because what is under test is the authorisation decision, not
# the login. That needs `docker exec` into the app container; set APP_CONTAINER
# if yours is not called baja-app.
#
# Every fixture this creates is named with a ZZ prefix and removed at the end,
# including on failure.

set -u

BASE="${1:-http://juiz.baja.local}"
# shellcheck disable=SC2206
CURL_ARGS=(${CURL_EXTRA:-})
APP="${APP_CONTAINER:-baja-app}"

PASS=0
FAIL=0
SKIP=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }
grey()  { printf '\e[90m%s\e[0m\n' "$1"; }

ok()   { green "PASS  $1"; PASS=$((PASS + 1)); }
bad()  { red   "FAIL  $1"; FAIL=$((FAIL + 1)); }
skip() { grey  "SKIP  $1"; SKIP=$((SKIP + 1)); }

same() { # name expected actual
    if [[ "$2" == "$3" ]]; then ok "$1 (got $3)"; else bad "$1 (expected $2, got $3)"; fi
}

contains() { # name needle haystack
    if grep -qF -- "$2" <<< "$3"; then ok "$1"; else bad "$1 (did not find \"$2\")"; fi
}

lacks() { # name needle haystack
    if grep -qF -- "$2" <<< "$3"; then bad "$1 (found \"$2\")"; else ok "$1"; fi
}

if ! docker exec "$APP" true 2>/dev/null; then
    skip "insertion page tests (no $APP container; set APP_CONTAINER)"
    echo
    echo "$PASS passed, $FAIL failed, $SKIP skipped"
    exit 0
fi

PREFIX_COM=zzhttpcom00000000000000000000000
PREFIX_SEM=zzhttpsem00000000000000000000000
PREFIX_NAO=zzhttpnao00000000000000000000000

cleanup() {
    docker exec "$APP" php -r '
        require "/var/www/html/vendor/autoload.php";
        require "/var/www/html/src/config.php";
        $r = new Redis();
        $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
        foreach (["'"$PREFIX_COM"'", "'"$PREFIX_SEM"'", "'"$PREFIX_NAO"'"] as $sid) {
            $r->del("baja:phpbb:session:" . $sid);
        }
        \Baja\Model\ParticipanteQuery::create()
            ->filterByNome("ZZHttp%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)
            ->delete();
        \Baja\Model\UserQuery::create()
            ->filterByUsername(["ZZHttpComPermissao", "ZZHttpSemPermissao"])
            ->delete();
    ' >/dev/null 2>&1
}
trap cleanup EXIT

# Two provisioned users, one holding `certificados` and one not, plus a third
# session for a forum account with no row here at all.
docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php";
    require "/var/www/html/src/config.php";
    foreach ([["ZZHttpComPermissao", ["index", "certificados"]], ["ZZHttpSemPermissao", ["index"]]] as [$nome, $perms]) {
        $u = \Baja\Model\UserQuery::create()->findOneByUsername($nome) ?? new \Baja\Model\User();
        $u->setUsername($nome);
        $u->setPermissions($perms);
        $u->save();
    }
    $r = new Redis();
    $r->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
    $r->setex("baja:phpbb:session:'"$PREFIX_COM"'", 900, json_encode(["user_id" => 2, "username" => "ZZHttpComPermissao"]));
    $r->setex("baja:phpbb:session:'"$PREFIX_SEM"'", 900, json_encode(["user_id" => 2, "username" => "ZZHttpSemPermissao"]));
    $r->setex("baja:phpbb:session:'"$PREFIX_NAO"'", 900, json_encode(["user_id" => 2, "username" => "ZZHttpNaoProvisionado"]));
' >/dev/null || { red "could not seed fixtures"; exit 1; }

status() { # sid path
    local jar=()
    [[ -n "$1" ]] && jar=(-b "phpbb3_baja_sid=$1; phpbb3_baja_u=2")
    curl -s "${CURL_ARGS[@]}" "${jar[@]}" -o /dev/null -w '%{http_code}' "$BASE$2"
}

body() { # sid path
    local jar=()
    [[ -n "$1" ]] && jar=(-b "phpbb3_baja_sid=$1; phpbb3_baja_u=2")
    curl -s "${CURL_ARGS[@]}" "${jar[@]}" "$BASE$2"
}

PAGINAS=(/certificados.php /certificados_lote.php /certificados_nome.php /certificados_busca.php /lote.php)

echo
echo "--- anonymous ---"
for pagina in "${PAGINAS[@]}"; do
    same "anonymous $pagina is turned away" 302 "$(status '' "$pagina")"
done
loc=$(curl -s "${CURL_ARGS[@]}" -o /dev/null -w '%{redirect_url}' "$BASE/certificados.php")
contains "and sent to the login page" "login.php" "$loc"

echo
echo "--- authenticated at the forum, no account here ---"
for pagina in "${PAGINAS[@]}"; do
    same "unprovisioned $pagina is refused" 403 "$(status "$PREFIX_NAO" "$pagina")"
done
contains "and told why, rather than bounced to login" \
    "ainda não tem acesso a este sistema" "$(body "$PREFIX_NAO" /certificados.php)"

echo
echo "--- provisioned, without certificados ---"
for pagina in "${PAGINAS[@]}"; do
    same "$pagina is refused without the permission" 403 "$(status "$PREFIX_SEM" "$pagina")"
done
negado=$(body "$PREFIX_SEM" /certificados.php)
contains "the refusal names the permission" "certificados" "$negado"
contains "and says it is not the same as being a judge" "permissões de juiz" "$negado"
lacks "and no form is rendered" "<form" "$negado"

indice=$(body "$PREFIX_SEM" /index.php)
lacks "the /juiz link is absent for them" 'href="certificados.php"' "$indice"

echo
echo "--- provisioned, with certificados ---"
for pagina in "${PAGINAS[@]}"; do
    same "$pagina renders" 200 "$(status "$PREFIX_COM" "$pagina")"
done

indice=$(body "$PREFIX_COM" /index.php)
contains "the /juiz link is present for them" 'href="certificados.php"' "$indice"

individual=$(body "$PREFIX_COM" /certificados.php)
contains "the page says it issues certificates" "Emissão de certificados" "$individual"
contains "and shows who is logged in" "ZZHttpComPermissao" "$individual"
contains "and carries a CSRF token" 'name="_csrf"' "$individual"
lacks "deprecated funcao Fiscal is not offered" '>Fiscal<' "$individual"
lacks "deprecated funcao Engenheiro is not offered" '>Engenheiro<' "$individual"
contains "but Comissão Técnica is" "Comissão Técnica" "$individual"

echo
echo "--- headers ---"
cabecalhos=$(curl -s "${CURL_ARGS[@]}" -D - -o /dev/null \
    -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" "$BASE/certificados.php")
contains "the page is not indexable" "X-Robots-Tag: noindex" "$cabecalhos"
contains "and not stored by a cache" "no-store" "$cabecalhos"

echo
echo "--- a state-changing POST needs its token ---"
antes=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    echo \Baja\Model\ParticipanteQuery::create()->filterByNome("ZZHttp%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->count();
')

semToken=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "evento=22BR" \
    --data-urlencode "nome=ZZHttp Fulano de Tal Testeson" \
    --data-urlencode "funcao=competidor" \
    --data-urlencode "documento=52998224725" \
    --data-urlencode "confirmar=1" \
    "$BASE/certificados.php")
contains "a POST with no token is refused" "sessão do formulário expirou" "$semToken"
lacks "and creates nothing" "Certificado criado" "$semToken"

tokenErrado=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "_csrf=$(printf 'a%.0s' {1..64})" \
    --data-urlencode "evento=22BR" \
    --data-urlencode "nome=ZZHttp Fulano de Tal Testeson" \
    --data-urlencode "funcao=competidor" \
    --data-urlencode "documento=52998224725" \
    --data-urlencode "confirmar=1" \
    "$BASE/certificados.php")
contains "a POST with a wrong token is refused" "sessão do formulário expirou" "$tokenErrado"

# A token minted for the other form must not work here either.
outroForm=$(docker exec "$APP" php -r 'echo hash_hmac("sha256", "certificado-lote", "'"$PREFIX_COM"'");')
trocado=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "_csrf=$outroForm" \
    --data-urlencode "evento=22BR" \
    --data-urlencode "nome=ZZHttp Fulano de Tal Testeson" \
    --data-urlencode "funcao=competidor" \
    --data-urlencode "documento=52998224725" \
    --data-urlencode "confirmar=1" \
    "$BASE/certificados.php")
contains "a token from the other form is refused" "sessão do formulário expirou" "$trocado"

depois=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    echo \Baja\Model\ParticipanteQuery::create()->filterByNome("ZZHttp%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->count();
')
same "three rejected POSTs created no rows" "$antes" "$depois"

echo
echo "--- and with the token, it works ---"
bomToken=$(docker exec "$APP" php -r 'echo hash_hmac("sha256", "certificado-individual", "'"$PREFIX_COM"'");')
criado=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "_csrf=$bomToken" \
    --data-urlencode "evento=22BR" \
    --data-urlencode "nome=ZZHttp Fulano de Tal Testeson" \
    --data-urlencode "funcao=competidor" \
    --data-urlencode "documento=52998224725" \
    --data-urlencode "confirmar=1" \
    "$BASE/certificados.php")
contains "a POST with the right token creates the certificate" "Certificado criado" "$criado"

auditoria=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    $row = \Baja\Model\ParticipanteQuery::create()
        ->filterByNome("ZZHttp%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->findOne();
    if (!$row) { echo "sem linha"; exit; }
    echo ($row->getToken() && $row->getCriadoPor() && $row->getCriadoEm() && $row->getLoteId())
        ? "completo" : "incompleto";
')
same "and the row it created carries its whole audit trail" "completo" "$auditoria"

echo
echo "--- the lookup page ---"
consulta=$(body "$PREFIX_COM" /certificados_busca.php)
contains "the lookup page offers an event filter" 'name="eventos[]"' "$consulta"
contains "and a role filter" 'name="funcoes[]"' "$consulta"
contains "and a name box" 'name="nome"' "$consulta"
contains "and a document box" 'name="documento"' "$consulta"
contains "with a cpf/passport/both selector" 'name="tipo_documento"' "$consulta"
contains "deprecated roles are searchable even though they are not issuable" 'value="fiscal"' "$consulta"

# A search posts. A document number in a query string is a document number in
# the access log, which is the thing the certificate work package exists to
# stop, and this vhost has no log redaction.
contains "the search form posts" 'method="post"' "$consulta"
lacks "and offers no GET route for a document" 'action="certificados_busca.php?' "$consulta"

busca_csrf=$(docker exec "$APP" php -r 'echo hash_hmac("sha256", "certificado-busca", "'"$PREFIX_COM"'");')
resultado=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "_csrf=$busca_csrf" \
    --data-urlencode "nome=ZZHttp*Testeson" \
    --data-urlencode "tipo_documento=ambos" \
    "$BASE/certificados_busca.php")
contains "a wildcard name search finds the row created above" "ZZHttp Fulano de Tal Testeson" "$resultado"

semToken=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
    --data-urlencode "nome=ZZHttp*Testeson" "$BASE/certificados_busca.php")
lacks "a search without a token returns nothing" "ZZHttp Fulano de Tal Testeson" "$semToken"

echo
echo "$PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]]
