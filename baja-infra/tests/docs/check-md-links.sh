#!/usr/bin/env bash
#
# Fails if a Markdown file under docs/ or baja-infra/ links to a file that
# does not exist. Run from anywhere; paths are resolved from the repo root.
#
#     baja-infra/tests/docs/check-md-links.sh
#
# Only relative links are checked: [text](path) and [text](path#anchor),
# resolved against the linking file's folder. Web links, mailto: and
# same-page anchors (#...) are skipped, as is anything inside a fenced
# code block. Anchors themselves are not checked, only that the file is
# there.
set -euo pipefail

cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

broken=0
checked=0

while IFS= read -r -d '' md; do
    dir="$(dirname "$md")"
    # Line number and every link target on lines outside ``` fences.
    while IFS=$'\t' read -r lineno target; do
        case "$target" in
            http://*|https://*|mailto:*|'#'*) continue ;;
        esac
        path="${target%%#*}"
        # A link may carry a title: [text](path "title").
        path="${path%% *}"
        [[ -n "$path" ]] || continue
        checked=$((checked + 1))
        if [[ ! -e "$dir/$path" ]]; then
            echo "$md:$lineno: broken link to $target"
            broken=$((broken + 1))
        fi
    done < <(awk '
        /^[[:space:]]*```/ { fence = !fence; next }
        fence { next }
        {
            line = $0
            while (match(line, /\]\([^)[:space:]]+( "[^"]*")?\)/)) {
                t = substr(line, RSTART + 2, RLENGTH - 3)
                print NR "\t" t
                line = substr(line, RSTART + RLENGTH)
            }
        }' "$md")
done < <(find docs baja-infra -name '*.md' -not -path '*/node_modules/*' -print0)

if (( broken > 0 )); then
    echo "$broken broken link(s) out of $checked checked." >&2
    exit 1
fi
echo "All $checked relative Markdown links resolve."
