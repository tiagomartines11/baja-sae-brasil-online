#!/usr/bin/env bash
# HTTP-level tests for the public certificate routes.
#
# The PHP suite (tests/certificado/run.php) covers the lookup rules; these
# cover the things that only exist once nginx and PHP are both in the path —
# routing, response headers, status codes, and what does or does not appear in
# the bytes sent to a browser.
#
# Run from the host against a stack serving the certificado vhost:
#   baja-php/tests/certificado/http-test.sh [base-url] [token] [cpf]
#
# The token must belong to a participant in that database. Without one, the
# tests that need a real certificate are skipped.

set -u

BASE="${1:-http://certificado.baja.local}"
TOKEN="${2:-}"
CPF="${3:-}"

PASS=0
FAIL=0
SKIP=0

green() { printf '\e[32m%s\e[0m\n' "$1"; }
red()   { printf '\e[31m%s\e[0m\n' "$1"; }
grey()  { printf '\e[90m%s\e[0m\n' "$1"; }

ok() {
    local name="$1" condition="$2" detail="${3:-}"
    if [[ "$condition" == "1" ]]; then
        green "PASS  $name"
        PASS=$((PASS + 1))
    else
        red "FAIL  $name${detail:+ ($detail)}"
        FAIL=$((FAIL + 1))
    fi
}

skip() {
    grey "SKIP  $1 ($2)"
    SKIP=$((SKIP + 1))
}

status_of() { curl -s -o /dev/null -w "%{http_code}" "$1"; }
body_of()   { curl -s "$1"; }
header_of() { curl -s -D - -o /dev/null "$1" | tr -d '\r'; }

# --- failure responses are indistinguishable ---------------------------------
#
# A well-formed token that does not exist, a token of the wrong length, and a
# junk path must all answer the same. Any difference between them tells a
# caller whether a token is real, and by extension whether a person competed.

unknown=$(body_of "$BASE/verificar/AAAAAAAAAAAAAAAAAAAAAA")
short=$(body_of "$BASE/verificar/AAAAAAAAAAAAAAAAAAAAA")
junk=$(body_of "$BASE/verificar/x/y/z")

ok "unknown token returns 404" \
   "$([[ $(status_of "$BASE/verificar/AAAAAAAAAAAAAAAAAAAAAA") == 404 ]] && echo 1 || echo 0)"
ok "malformed token returns 404" \
   "$([[ $(status_of "$BASE/verificar/AAAAAAAAAAAAAAAAAAAAA") == 404 ]] && echo 1 || echo 0)"
ok "unknown and malformed tokens return byte-identical bodies" \
   "$([[ "$unknown" == "$short" ]] && echo 1 || echo 0)"
ok "junk under /verificar/ returns the same body" \
   "$([[ "$unknown" == "$junk" ]] && echo 1 || echo 0)"

# --- /buscar tells nobody whether a document exists --------------------------
#
# The body for "no such document" and for "document found, but no row's name
# matched" must be identical to the byte. Any difference — a count, a word, a
# whitespace change — answers "did this person compete?" to anyone who asks.

post_buscar() { curl -s -X POST --data-urlencode "documento=$1" --data-urlencode "nome=$2" "$BASE/buscar"; }

unknown_doc=$(post_buscar "98765432100" "Alguem Improvavel Inexistente")
wrong_name=$(post_buscar "00012345678" "Nome Que Nao Confere")
bare_first=$(post_buscar "00012345678" "Joao")

ok "unknown document and wrong name give byte-identical bodies" \
   "$([[ "$unknown_doc" == "$wrong_name" ]] && echo 1 || echo 0)" \
   "${#unknown_doc} vs ${#wrong_name} bytes"
ok "a rejected bare first name gives the same body too" \
   "$([[ "$unknown_doc" == "$bare_first" ]] && echo 1 || echo 0)"

buscar_headers=$(header_of "$BASE/buscar")
ok "/buscar sends Cache-Control: no-store" \
   "$(grep -qi '^Cache-Control:.*no-store' <<<"$buscar_headers" && echo 1 || echo 0)"
ok "/buscar sends X-Robots-Tag: noindex" \
   "$(grep -qi '^X-Robots-Tag: noindex' <<<"$buscar_headers" && echo 1 || echo 0)"
ok "/buscar carries no Google Analytics tag" \
   "$(grep -qi 'googletagmanager\|gtag(\|google-analytics' <<<"$(body_of "$BASE/buscar")" && echo 0 || echo 1)"
ok "/buscar does not use a number input, which would eat a leading zero" \
   "$(grep -qi 'type="number"' <<<"$(body_of "$BASE/buscar")" && echo 0 || echo 1)"

# --- the legacy routes resolve nothing ---------------------------------------
#
# /c/{evt}/{cpf-hex} is printed on every certificate ever issued, so it must
# keep answering indefinitely. It must also never resolve a certificate again,
# and must never carry the CPF onward — not in the Location header, not as a
# query parameter to prefill the form.

legacy_headers=$(header_of "$BASE/c/26BR/c56f0bb55")
legacy_status=$(status_of "$BASE/c/26BR/c56f0bb55")
legacy_location=$(grep -i '^Location:' <<<"$legacy_headers")

ok "legacy /c/{evt}/{hex} returns 302" "$([[ "$legacy_status" == 302 ]] && echo 1 || echo 0)" "got $legacy_status"
ok "legacy route redirects to /buscar" \
   "$(grep -q '/buscar' <<<"$legacy_location" && echo 1 || echo 0)" "$legacy_location"
ok "legacy redirect carries no CPF in any encoding" \
   "$(grep -qi 'c56f0bb55\|52998224725\|cpf' <<<"$legacy_location" && echo 0 || echo 1)" "$legacy_location"
ok "legacy route does not return a PDF" \
   "$(grep -qi 'application/pdf' <<<"$legacy_headers" && echo 0 || echo 1)"

direct_headers=$(header_of "$BASE/certificado.php?evt=26BR&cpf=c56f0bb55")
ok "certificado.php resolves nothing directly either" \
   "$(grep -qi 'application/pdf' <<<"$direct_headers" && echo 0 || echo 1)"
ok "certificado.php redirects to /buscar" \
   "$(grep -qi '^Location:.*\/buscar' <<<"$direct_headers" && echo 1 || echo 0)"

post_legacy=$(curl -s -D - -o /dev/null -X POST -d 'evt=26BR&cpf=52998224725' "$BASE/c/novo/certificado" | tr -d '\r')
ok "POST to the old form target resolves nothing" \
   "$(grep -qi 'application/pdf' <<<"$post_legacy" && echo 0 || echo 1)"
ok "POST to the old form target redirects to /buscar" \
   "$(grep -qi '^Location:.*\/buscar' <<<"$post_legacy" && echo 1 || echo 0)"

root_headers=$(header_of "$BASE/")
ok "the site root no longer serves an event selector" \
   "$(grep -qi '^Location:.*\/buscar' <<<"$root_headers" && echo 1 || echo 0)"

# --- headers -----------------------------------------------------------------

headers=$(header_of "$BASE/verificar/AAAAAAAAAAAAAAAAAAAAAA")
ok "not-found page sends X-Robots-Tag: noindex" \
   "$(grep -qi '^X-Robots-Tag: noindex' <<<"$headers" && echo 1 || echo 0)"

if [[ -z "$TOKEN" ]]; then
    skip "certificate page tests" "no token given as \$2"
    printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
    [[ "$FAIL" -eq 0 ]] || exit 1
    exit 0
fi

page=$(body_of "$BASE/verificar/$TOKEN")
page_headers=$(header_of "$BASE/verificar/$TOKEN")
pdf_headers=$(header_of "$BASE/verificar/$TOKEN/pdf")

ok "certificate page returns 200" \
   "$([[ $(status_of "$BASE/verificar/$TOKEN") == 200 ]] && echo 1 || echo 0)"
ok "certificate page sends X-Robots-Tag: noindex" \
   "$(grep -qi '^X-Robots-Tag: noindex' <<<"$page_headers" && echo 1 || echo 0)"
ok "certificate page sends Referrer-Policy: no-referrer" \
   "$(grep -qi '^Referrer-Policy: no-referrer' <<<"$page_headers" && echo 1 || echo 0)"
ok "certificate page sends Cache-Control: no-store" \
   "$(grep -qi '^Cache-Control:.*no-store' <<<"$page_headers" && echo 1 || echo 0)"

# --- no Google Analytics -----------------------------------------------------

ok "certificate page carries no Google Analytics tag" \
   "$(grep -qi 'googletagmanager\|gtag(\|google-analytics' <<<"$page" && echo 0 || echo 1)"

# --- SAE BRASIL identity, not Baja -------------------------------------------
#
# The system issues certificates for several SAE BRASIL student programmes.
# Event names legitimately contain "Baja" — that is what the event is called —
# but no Baja mark or asset belongs in the page's own furniture.

ok "page carries the SAE BRASIL wordmark" \
   "$(grep -q 'sae-brasil-wordmark' <<<"$page" && echo 1 || echo 0)"
ok "wordmark asset is served" \
   "$([[ $(status_of "$BASE/img/sae-brasil-wordmark.png") == 200 ]] && echo 1 || echo 0)"
ok "no Baja logo asset in the page furniture" \
   "$(grep -qi 'baja_grande\|img/baja\.' <<<"$page" && echo 0 || echo 1)"
ok "page title is SAE BRASIL" \
   "$(grep -qi '<title>[^<]*SAE BRASIL' <<<"$page" && echo 1 || echo 0)"

# --- the page is not the PDF -------------------------------------------------

ok "certificate page is HTML, not a PDF" \
   "$(grep -qi '^Content-Type: text/html' <<<"$page_headers" && echo 1 || echo 0)"
ok "PDF route returns a PDF" \
   "$(grep -qi '^Content-Type: application/pdf' <<<"$pdf_headers" && echo 1 || echo 0)"

# --- the download filename carries the token, not a document -----------------

ok "download filename is certificado_{token}.pdf" \
   "$(grep -qi "filename=\"certificado_${TOKEN}.pdf\"" <<<"$pdf_headers" && echo 1 || echo 0)" \
   "$(grep -i 'content-disposition' <<<"$pdf_headers")"

# --- no sibling certificates, no document number -----------------------------
#
# The page must show this row and nothing else. Counting the token occurrences
# catches a stray link to another certificate; the only ones expected are the
# PDF button and any self-reference.

other_tokens=$(grep -o '/verificar/[A-Za-z0-9_-]\{22\}' <<<"$page" | grep -v "$TOKEN" | wc -l)
ok "no link to any other certificate" "$([[ "$other_tokens" -eq 0 ]] && echo 1 || echo 0)" \
   "found $other_tokens"

ok "page does not offer to list the participant's other certificates" \
   "$(grep -qi 'outros certificados\|demais certificados\|todos os certificados' <<<"$page" && echo 0 || echo 1)"

# --- the document number appears nowhere, in any encoding --------------------

if [[ -z "$CPF" ]]; then
    skip "document number absent from the page" "no CPF given as \$3"
else
    digits="${CPF//[^0-9]/}"
    unpadded="$(sed 's/^0*//' <<<"$digits")"
    hex="$(printf '%x' "$unpadded")"
    punctuated="${digits:0:3}.${digits:3:3}.${digits:6:3}-${digits:9:2}"

    leaked=0
    for form in "$digits" "$unpadded" "$hex" "$punctuated"; do
        if grep -qi -- "$form" <<<"$page"; then
            red "        leaked as: $form"
            leaked=1
        fi
    done
    ok "document number absent from the certificate page in every encoding" \
       "$([[ "$leaked" -eq 0 ]] && echo 1 || echo 0)"

    ok "document number absent from the PDF response headers" \
       "$(grep -qi -- "$hex" <<<"$pdf_headers" && echo 0 || echo 1)"
fi

# --- the page does not pay for a PDF render ----------------------------------
#
# The whole argument for an HTML confirmation page is that dompdf is the most
# expensive thing here and most verifications do not need it. If the page ever
# starts rendering one, this is what notices.

page_ms=$(curl -s -o /dev/null -w '%{time_total}' "$BASE/verificar/$TOKEN" | tr -d '.' | sed 's/^0*//')
pdf_ms=$(curl -s -o /dev/null -w '%{time_total}' "$BASE/verificar/$TOKEN/pdf" | tr -d '.' | sed 's/^0*//')
ok "certificate page is much cheaper than the PDF it links to" \
   "$([[ "${page_ms:-0}" -lt "${pdf_ms:-1}" ]] && echo 1 || echo 0)" \
   "page ${page_ms}, pdf ${pdf_ms} (microseconds)"

printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
[[ "$FAIL" -eq 0 ]] || exit 1
