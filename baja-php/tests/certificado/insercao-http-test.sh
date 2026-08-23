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

PAGINAS=(/certificados.php /certificados_lote.php /certificados_nome.php /certificados_busca.php /lotes.php /lote.php)

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
echo "--- a batch committed in part ---"
lote_csrf=$(docker exec "$APP" php -r 'echo hash_hmac("sha256", "certificado-lote", "'"$PREFIX_COM"'");')

# Two rows that are ready and two that are not: a deprecated funcao nobody has
# confirmed, and a funcao that does not exist.
# Documents of their own. Reusing one that another fixture already holds makes
# these rows name conflicts instead, which is correct behaviour and not what
# this section is testing. Check digits derived, never real.
colagem=$(printf '22BR\tZZHttp Parcial Um Testeson\tcompetidor\t81818181800\t\n22BR\tZZHttp Parcial Dois Testeson\tjuiz\t\tZZ777888\n22BR\tZZHttp Parcial Tres Testeson\tfiscal\t82828282899\t\n22BR\tZZHttp Parcial Quatro Testeson\tnaoexiste\t\tZZ777999\n')

lote_post() { # etapa lote_alvo
    curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
        --data-urlencode "_csrf=$lote_csrf" \
        --data-urlencode "colado=$colagem" \
        --data-urlencode "colunas[0]=evento" --data-urlencode "colunas[1]=nome" \
        --data-urlencode "colunas[2]=funcao" --data-urlencode "colunas[3]=cpf" \
        --data-urlencode "colunas[4]=passaporte" \
        --data-urlencode "etapa=$1" --data-urlencode "lote_alvo=${2:-}" \
        "$BASE/certificados_lote.php"
}

revisao=$(lote_post revisar "")
contains "the review offers to commit only the resolved rows" 'value="gravar_parcial"' "$revisao"

# The primary button is never disabled. A disabled button is the one control
# that cannot say why it will not work: choosing a radio does not re-enable it,
# there is no script to do so, and pressing it does nothing at all — which is
# how somebody answers a question and then finds the page ignoring them.
lacks "the commit button is never rendered inert" 'value="gravar" disabled' "$revisao"

recusado=$(lote_post gravar "")
contains "pressing it unanswered says nothing was created" "Nada foi criado" "$recusado"
contains "and says a decision is missing" "decis" "$recusado"
alvo=$(printf '%s' "$revisao" | grep -o 'name="lote_alvo" value="[A-Za-z0-9_-]\{22\}"' | head -1 | sed 's/.*value="//; s/"//')
if [[ -n "$alvo" ]]; then ok "the review carries a batch id"; else bad "the review carries a batch id"; fi

parcial=$(lote_post gravar_parcial "$alvo")
contains "a partial commit reports the batch" "Lote criado" "$parcial"
contains "and says what was left behind" "de fora" "$parcial"
contains "handing the leftovers back as a sheet" 'name="colado"' "$parcial"
contains "with a button to carry on with them" 'Continuar com' "$parcial"
contains "the leftover sheet names the deprecated row" "ZZHttp Parcial Tres Testeson" "$parcial"
lacks "and does not include a row that was created" "ZZHttp Parcial Um Testeson<" "$parcial"

criadas=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    echo \Baja\Model\ParticipanteQuery::create()
        ->filterByNome("ZZHttp Parcial%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->count();
')
same "only the resolved rows were created" 2 "$criadas"

# What a browser sends when somebody presses F5 on that result.
repetido=$(lote_post gravar_parcial "$alvo")
contains "resending the commit is recognised, not repeated" "já foi criado" "$repetido"
lacks "and does not claim to have created anything" "Lote criado" "$repetido"

aindaCriadas=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    echo \Baja\Model\ParticipanteQuery::create()
        ->filterByNome("ZZHttp Parcial%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->count();
')
same "so the row count is unchanged" 2 "$aindaCriadas"

echo
echo "--- the batch list ---"
listaLotes=$(body "$PREFIX_COM" /lotes.php)
contains "the batch list offers an event filter" 'name="eventos[]"' "$listaLotes"
contains "and an author filter" 'name="autor"' "$listaLotes"
contains "and a batch id box" 'name="id"' "$listaLotes"
contains "and links to a batch" 'lote.php?id=' "$listaLotes"

# GET here, unlike the certificate lookup. That page posts because a document
# number in a URL is a document number in the access log; nothing on this page
# is a document number or a name, so its filters can live in the address and a
# view can be sent to somebody.
contains "the filter form uses GET" 'method="get"' "$listaLotes"

contains "a batch page links back to the list" 'lotes.php' "$(body "$PREFIX_COM" /lote.php)"

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
echo "--- voiding a certificate ---"
busca_csrf2=$(docker exec "$APP" php -r 'echo hash_hmac("sha256", "certificado-busca", "'"$PREFIX_COM"'");')

alvoToken=$(docker exec "$APP" php -r '
    require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
    $row = \Baja\Model\ParticipanteQuery::create()
        ->filterByNome("ZZHttp Fulano%", \Propel\Runtime\ActiveQuery\Criteria::LIKE)->findOne();
    echo $row ? $row->getToken() : "";
')

if [[ -z "$alvoToken" ]]; then
    skip "voiding tests (no row to void)"
else
    anular() { # acao vistos motivo
        curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
            --data-urlencode "_csrf=$busca_csrf2" \
            --data-urlencode "tokens[]=$alvoToken" \
            --data-urlencode "acao=$1" \
            --data-urlencode "vistos=$2" \
            --data-urlencode "motivo=$3" \
            --data-urlencode "estado=todos" \
            "$BASE/certificados_busca.php"
    }

    previa=$(anular anular "" "")
    contains "voiding shows what it will change first" "ZZHttp Fulano de Tal Testeson" "$previa"
    contains "and names the certificate" "$alvoToken" "$previa"
    contains "and asks for a reason" 'name="motivo"' "$previa"
    contains "and says the row is not deleted" "não é apagada" "$previa"

    semMotivo=$(anular anular_confirmado 1 "")
    contains "a confirmation with no reason is refused" "Diga por que" "$semMotivo"

    aindaValido=$(curl -s "${CURL_ARGS[@]}" -o /dev/null -w '%{http_code}' \
        "$(docker exec "$APP" php -r '
            require "/var/www/html/vendor/autoload.php";
            echo \Baja\Url::subdomain("certificado", "/verificar/'"$alvoToken"'");
        ')" 2>/dev/null || echo "000")
    grey "      (public check of $alvoToken before voiding: $aindaValido)"

    desatualizado=$(anular anular_confirmado 9 "motivo qualquer")
    contains "a stale count is refused" "A lista mudou" "$desatualizado"

    feito=$(anular anular_confirmado 1 "anulado pelo teste automatizado")
    contains "voiding with a reason succeeds" "anulado" "$feito"

    estadoRow=$(docker exec "$APP" php -r '
        require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
        $row = \Baja\Model\ParticipanteQuery::create()->filterByToken("'"$alvoToken"'")->findOne();
        if (!$row) { echo "linha apagada"; exit; }
        echo ($row->getAnuladoEm() && $row->getAnuladoPor() && $row->getAnuladoMotivo())
            ? "anulado com registro" : "sem registro";
    ')
    same "the row survives, carrying who voided it and why" "anulado com registro" "$estadoRow"

    naoResolve=$(docker exec "$APP" php -r '
        require "/var/www/html/vendor/autoload.php"; require "/var/www/html/src/config.php";
        echo \Baja\Certificado\Certificado::fromToken("'"$alvoToken"'") === null ? "nao resolve" : "ainda resolve";
    ')
    same "and the certificate no longer resolves for /verificar" "nao resolve" "$naoResolve"

    escondido=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
        --data-urlencode "_csrf=$busca_csrf2" --data-urlencode "nome=ZZHttp*Testeson" \
        --data-urlencode "tipo_documento=ambos" --data-urlencode "estado=validos" \
        "$BASE/certificados_busca.php")
    lacks "a voided certificate is out of the default lookup" "$alvoToken" "$escondido"

    visivel=$(curl -s "${CURL_ARGS[@]}" -b "phpbb3_baja_sid=$PREFIX_COM; phpbb3_baja_u=2" -X POST \
        --data-urlencode "_csrf=$busca_csrf2" --data-urlencode "nome=ZZHttp*Testeson" \
        --data-urlencode "tipo_documento=ambos" --data-urlencode "estado=anulados" \
        "$BASE/certificados_busca.php")
    contains "but is listed when they ask for voided ones" "$alvoToken" "$visivel"
    contains "with the reason on show" "anulado pelo teste automatizado" "$visivel"
fi

echo
echo "$PASS passed, $FAIL failed, $SKIP skipped"
[[ $FAIL -eq 0 ]]
