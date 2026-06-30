# Dev workflow

How we branch, review, and ship in this repo. Written for the team
internally — not a polished public guide. If something here doesn't
match what's actually happening, the doc is wrong; fix it.

## Branches

- **`master`** — production. Whatever's here is what's live (or
  next to go live). Don't push directly; PRs only.
- **`develop`** — integration branch. Feature work lands here
  first. Generally green at all times — broken `develop` blocks
  everyone.
- **Feature branches** — `feature/<short-name>`, `fix/<bug>`,
  `chore/<thing>`. Short-lived. Branch off `develop`, PR back to
  `develop`.
- **Hotfix branches** — `hotfix/<urgent-thing>`. Branch off
  `master`, PR back to `master`. After merging to `master`, also
  merge `master` → `develop` so the fix isn't undone by the next
  release.

## Cadence

- Feature PRs → `develop` continuously.
- `develop` → `master` merges happen periodically when the
  accumulated work is worth a release. No fixed schedule; usually
  driven by "we have N things ready and nothing in flight that we'd
  block on."
- Production deploy is triggered by the merge into `master`. Same
  commit gets tagged in case we need to roll back.

## Pre-merge checklist (PRs into `develop`)

Before requesting review:

- [ ] Lint passes locally: (`baja-js`: `cd baja-js && npm run lint`; baja-php: TBD, `php -l` for syntax, not enforced linter yet).
- [ ] Smoke test passes: `baja-php/tests/smoke-test.sh` against a
      running local stack.
- [ ] If you touched `baja-php/src/` Propel models, the generated
      `Base*` classes were regenerated and committed
      (`./baja-php/propel om`).
- [ ] If you touched `baja-php/phpbb-extensions/baja-auth/`, you
      rebuilt phpbb-baja and verified the auth flow:
      `cd baja-infra && docker compose down -v && docker compose up -d`
      then re-run smoke-test.sh.
- [ ] If you touched `baja-js/`, `npm run build` succeeds.
- [ ] No commented-out code left behind. No `console.log` /
      `var_dump` / debug prints.
- [ ] Commit messages explain *why*, not just *what*.

## Pre-merge checklist (`develop` → `master`)

- [ ] All items above, but for the cumulative diff.
- [ ] Quick manual run-through of the touched user flows (login,
      whatever the headline change is, one cert generation, one
      results page).
- [ ] Check for any TODO/FIXME tagged for "before release" — those
      are blockers.
- [ ] Skim the database migration list (`baja-php/generated-migrations/`,
      phpBB migrations) — flag anything risky for the deploy notes.

## Deploy

> **TODO** — production deploy pipeline is the next major work
> package after WP6. For now this section is aspirational; current
> reality is "manual SSH + git pull + restart."

Once the pipeline is in place, this section will cover:

- What triggers prod (merge to `master`, manual workflow dispatch?)
- How to verify it landed cleanly (which dashboards, which canary
  checks).
- How to roll back: revert the merge, re-deploy. Tag the failed
  commit so we don't retry it accidentally.

## Competition freeze policy

**No merges to `master` 7 days before a competition event, and
none during the event.** Hotfixes only, with explicit sign-off
from whoever is running ops for that event.

The 7-day window is to give the system time to surface late-binding
bugs (caching, slow queries that only show under real load) before
teams start arriving on-site. During the event itself, the live
demo gods punish anyone who deploys for fun.

Hotfixes during freeze still go through the normal hotfix-branch
flow (`hotfix/...` off `master`, PR, merge, back-merge to
`develop`). They just bypass the "no merge" rule because they
exist to put the fire out.

## FAQ

**Q: I made changes to a phpBB extension file and ran `docker
compose up -d` but my changes aren't showing up.**
A: phpBB extensions are baked into the image at build time, AND
the `phpbb_baja_html` volume retains its initial content across
container restarts. You need:
```
cd baja-infra
docker compose down -v
docker compose build phpbb-baja
docker compose up -d
```
The `-v` is what wipes the volume so your fresh build actually
takes effect. See [`baja-php/docs/baja-auth-extension.md`](../baja-php/docs/baja-auth-extension.md)
for the longer explanation.

**Q: I changed a value in `.env` but it's not taking effect.**
A: Compose reads `.env` at `up` time, not for already-running containers.
After editing `.env`: `docker compose up -d` (recreates affected
containers).

phpBB DB passwords no longer need a rebuild — `config.php` is generated
at container boot from `phpbb-baja/config.php.template` and
`phpbb-formula/config.php.template` via `envsubst`, reading directly from
the live environment. Changing `MYSQL_PHPBB_BAJA_PASSWORD` or
`MYSQL_PHPBB_FORMULA_PASSWORD` only requires:
    docker compose down -v
    docker compose up -d
(`-v` resets the volume holding the previous `config.php`; no `build`
step needed.) See `baja-infra/.env.example` for the full list of values
and which ones drive this template.

**Q: What about the data in the dev DB after `down -v`?**
A: It gets re-seeded from `baja-infra/mysql/init/`. If you've made
local DB changes you want to keep, dump first
(`docker exec baja-mysql mysqldump ...`) before the `down -v`.

**Q: Hostnames don't resolve — `curl: (6) Could not resolve host`.**
A: You need entries in your hosts file. On WSL2, edit Windows
`C:\Windows\System32\drivers\etc\hosts` (WSL inherits from there):
```
127.0.0.1 baja.local resultados.baja.local juiz.baja.local fila.baja.local certificado.baja.local forum.baja.local
```

**Q: Where do PHP changes go vs JS changes?**
A: Existing functionality (judging, fila, results, certificates)
stays in `baja-php/`. New UI work and the recruitment site live in
`baja-js/`. The migration of legacy UI to JS is a separate
multi-WP effort, not covered here. When in doubt, ask before
duplicating logic across the two.

**Q: My PR has lint failures only on Windows line endings.**
A: This repo's PHP files use CRLF (legacy convention). Configure
your editor to preserve existing line endings rather than rewriting
them. The TS/JS files in `baja-js/` use LF (Next.js convention).
Don't normalize across the boundary.

**Q: I want to add a new container/service.**
A: Edit `baja-infra/docker-compose.yml`. If it needs a custom
image, add a `baja-infra/<service>/Dockerfile`. If it needs to be
proxied through nginx, add a config in
`baja-infra/nginx/conf.d/`. The phpbb-baja and baja-js services
are good templates for either pattern.
