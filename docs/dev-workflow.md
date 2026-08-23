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
- [ ] If you touched anything under `baja-php/src/Baja/Certificado/`,
      `baja-php/certificado/`, `baja-php/juiz/certificados*`, or either
      the certificate or juiz vhost, all three certificate suites pass:

      ```
      docker compose exec --user "$(id -u):$(id -g)" \
          -e BAJA_TEST_DB=1 baja-app php tests/certificado/run.php
      baja-php/tests/certificado/http-test.sh http://certificado.baja.local <token> <cpf>
      baja-php/tests/certificado/insercao-http-test.sh http://juiz.baja.local
      ```

      The first covers the lookup and validation rules and needs
      `BAJA_TEST_DB=1`, which is what lets it write synthetic
      participants — never set it against production. The second covers
      what only exists once nginx and PHP are both in the path: routing,
      headers, rate limiting, and whether a document number can be found
      anywhere in a response. Pass it a token from your local database
      and that participant's CPF.

      The third covers the insertion pages, which are the only
      state-changing surface here: who is turned away and how, and that
      a POST without a valid CSRF token creates nothing. It seeds its
      own sessions through `docker exec` into the app container, so
      set `APP_CONTAINER` if yours is not called `baja-app`, and it
      skips rather than fails when it cannot reach one.

      All three use synthetic documents with derived check digits, and
      remove their own fixtures. This repository is public: no real CPF,
      name, or dump belongs in a fixture.
- [ ] If you edited `baja-php/schema.xml`, the generated `Base*` classes,
      table maps and `src/loadDatabase.php` were regenerated and committed:
      `docker compose exec --user "$(id -u):$(id -g)" baja-app ./propel model:build`.
      (Was documented as `propel om` — that is Propel 1 syntax and the
      command does not exist in Propel 2.) Generator settings come from the
      committed `baja-php/propel.yaml.dist`; codegen needs no database.

      **Do not drop the `--user` flag.** The container runs as root, and the
      source tree is bind-mounted, so without it every generated file lands
      on your host owned by `root:root` — git won't notice (it doesn't track
      ownership) but you won't be able to edit them afterwards without
      `sudo`. The same applies to any command that writes into the tree,
      e.g. `sql:build` or generating `ApiDocs.json`.
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

**Q: How do I change a forum's logo?**
A: Drop an SVG at `baja-infra/phpbb-baja/branding/site_logo.svg` (or
`phpbb-formula/`), then:
```
cd baja-infra
docker compose build phpbb-baja
docker compose up -d phpbb-baja
docker compose restart nginx
```
No `down -v` here, unlike extensions above — branding is copied into the
volume by the entrypoint on every boot precisely so logo changes don't cost
a volume wipe. It replaces prosilver's own `site_logo.svg`, so no CSS
change is needed. Note that nginx serves `.svg` with `expires 30d` and the
filename doesn't change, so hard-refresh before concluding it didn't work.
See [`baja-infra/phpbb-baja/branding/README.md`](../baja-infra/phpbb-baja/branding/README.md).

**That last `restart nginx` is not optional** — see the next entry.

**Q: After `docker compose up -d`, one forum 502s and the other serves the
wrong board. What happened?**
A: nginx resolves `fastcgi_pass phpbb-baja:9000` **once, at config load**,
and caches the IP. Any `up -d` that *recreates* a phpBB container gives it a
new IP on the `baja` bridge, and nginx keeps pointing at the old one. The
failure is nastier than a plain outage, because the freed IP usually gets
reused by the *other* phpBB container:

- `forum.baja.local` → dead IP → **502**
- `forum.formula.local` → the IP now held by phpbb-baja → **serves the Baja
  board under the Formula hostname**

Static files still look right (nginx serves those from the volume itself),
so the logo/CSS can be perfectly correct while the PHP behind it is the
wrong forum entirely. Don't debug the app — check the IPs:
```
docker inspect -f '{{.Name}} {{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' \
  baja-nginx baja-phpbb-baja baja-phpbb-formula
docker logs baja-nginx | grep 'connect() failed'   # shows the stale upstream IP
```
`docker compose restart nginx` fixes it by forcing re-resolution. `docker
compose restart <phpbb service>` does *not* trigger this — restart keeps the
IP; only recreation changes it.

The real fix is to make nginx re-resolve per request (Docker's embedded DNS
at `127.0.0.11` plus a variable `fastcgi_pass`) rather than relying on
everyone remembering the restart. That's a change across every file in
`nginx/conf.d/` and `nginx/conf.d-prod/` and hasn't been done yet.

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
