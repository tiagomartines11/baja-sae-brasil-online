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
  `develop` is the repository's default branch, so new PRs and fresh
  clones start there: for a hotfix, branch from `origin/master` and
  pick `master` as the PR base by hand (`gh pr create --base master`).

## Cadence

- Feature PRs → `develop` continuously.
- `develop` → `master` merges happen periodically when the
  accumulated work is worth a release. No fixed schedule; usually
  driven by "we have N things ready and nothing in flight that we'd
  block on."
- Production deploy is triggered by the merge into `master`. Same
  commit gets tagged in case we need to roll back. See
  [Releases](#releases).

## Local setup

`baja-infra/.env` must set `STAFF_COOKIE_VALUE`, or `docker compose`
refuses to run ("set STAFF_COOKIE_VALUE in .env, see .env.example"). It
is the value of the `baja_staff` cookie that lets judges see results
pages past spoilers. Any value works in dev; `.env.example` has one.
Production and staging each need their own, generated with
`openssl rand -hex 24`. See
[`results-caching.md`](results-caching.md), "The staff cookie".

## Pre-merge checklist (PRs into `develop`)

Before requesting review:

- [ ] Lint passes locally: (`baja-js`: `cd baja-js && npm run lint`; baja-php: TBD, `php -l` for syntax, not enforced linter yet).
- [ ] Smoke test passes: `baja-php/tests/smoke-test.sh` against a
      running local stack.
- [ ] If you touched anything under `baja-php/src/Baja/Certificado/`,
      `baja-php/certificado/`, `baja-php/juiz/certificados*`,
      `baja-php/src/Baja/Util/Mailer.php`, `Turnstile.php`,
      `baja-php/src/Baja/Auth/PhpbbEmails.php`, or either the certificate
      or juiz vhost, all three certificate suites pass:

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

      **A fresh dev database needs the Propel migrations first.** The seed
      in `baja-infra/mysql/init/` records as applied only the migrations
      whose effect is already in its DDL; the certificate ones (the
      `participantes` audit and annulment columns, `certificado_requerimento`)
      are not, so after `docker compose down -v && docker compose up -d`
      `run.php` dies on its first insert with "Unknown column 'criado_por'".
      Run, from `baja-infra/`:

      ```
      docker compose exec --user "$(id -u):$(id -g)" baja-app ./propel migration:migrate \
          --connection="resultados=mysql:host=mysql;dbname=baja_resultados;user=resultados;password=devresultados"
      ```

      (`devresultados` is the dev `MYSQL_RESULTADOS_PASSWORD` from
      `.env.example`; use yours if you changed it.) The migrations that are
      already in the seed report nothing to do. Also run `run.php` and
      `insercao-http-test.sh` one after the other, not at the same time:
      both create partial batches with overlapping synthetic documents and
      make each other's counts wrong.
- [ ] If you touched anything that computes or shows results
      (`baja-php/resultados/prova*.php`, `torneio.php`,
      `baja-php/src/Baja/Results/`, `baja-php/src/Baja/Premiacao/`,
      `Input::solveFormula` and the other
      formula code in `src/Baja/Model/Input.php` / `Prova.php`,
      `src/Baja/Site/Template.php`), the results builder's unit tests pass
      (`vendor/bin/phpunit --testsuite unit`, see `tests/Unit/Results/`) and
      the results snapshots still match:

      ```
      docker compose exec --user "$(id -u):$(id -g)" baja-app php scripts/results-snapshot.php --check
      ```

      If they differ on purpose, accept with `--update` and say in the PR
      which results change and why. CI runs `--check` too. See
      [`results-snapshots.md`](results-snapshots.md).
- [ ] If you touched the public results pages (`baja-php/resultados/`),
      `Template.php`, `src/Baja/Site/CachePolicy.php` / `CleanUrl.php`,
      `src/Baja/Auth/StaffCookie.php`, the session code in
      `src/bootstrap.php` / `src/Baja/Session.php`, or the resultados vhost,
      the results cache tests pass against a running stack:

      ```
      baja-php/tests/results-caching/http-test.sh http://resultados.baja.local http://juiz.baja.local
      ```

      They check that a visitor without the staff cookie always gets the
      same cacheable page and no cookie, and that only a judge's session sees
      past spoilers, never cached. See
      [`results-caching.md`](results-caching.md), "Tests".
- [ ] If you touched the public results API (`baja-php/api/resultados/`,
      `baja-php/src/Baja/Api/`) or anything the results-builder bullet
      above lists, the
      API's suites pass: `vendor/bin/phpunit --testsuite unit` and
      `--testsuite results-api-db` (CI runs both), and, if you touched the
      API vhost, `baja-php/tests/Api/results-http-test.sh`. Its contract is
      [`results-api.md`](results-api.md): add fields, never rename or
      remove one.
- [ ] If you touched the backups (`baja-infra/scripts/backup-*.sh`,
      `verify-*.sh`, `archive-event.sh`, `erasure-log.sh`,
      `baja-infra/docker/backup/`, `baja-infra/gas/retention/`, the
      `backup`/`results-archive` services, or `src/Baja/ResultsArchive/`),
      `baja-infra/tests/backup/run-tests.sh` and the pruner's
      `node baja-infra/gas/retention/test/prune.test.js` pass (CI runs
      both), and `baja-infra/tests/backup/stack-test.sh` passes against your
      running stack. Anything that writes files somewhere new must be added
      as a file set; see "What is not in the backups" in
      [`baja-infra/docs/backup-restore.md`](../baja-infra/docs/backup-restore.md).
- [ ] If you touched a page in `baja-php/juiz/` or anything that prints
      stored text, it escapes what it prints and its forms carry a CSRF
      token (BAJAAPP-41):
      - print names, labels, options and anything typed through
        `Baja\Site\Html::e()`, in text and in quoted attributes, and data
        inside `<script>` through `Html::js()`. Results cells (formula
        output) are the one exception: they are meant to hold HTML;
      - a form that changes something has `<?= Csrf::field() ?>`, and the
        page calls `Csrf::enforce()` before changing anything
        (`src/Baja/Auth/Csrf.php`). A script sends the token from
        `<meta name="csrf-token">` as the `X-CSRF-Token` header. Never
        change anything on a GET;
      - `vendor/bin/phpunit --testsuite unit` passes: `CsrfCoverageTest`
        fails for a judge page that writes without a check, or shows a POST
        form without a token;
      - add the form to `baja-php/tests/csrf/cases/` and run, against a
        running stack,
        `baja-php/tests/csrf/http-test.sh http://juiz.baja.local`.
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

## Releases

A release is `develop` merged into `master`, tagged `vN`. A hotfix on top
of a release is tagged `vN.M`: the first hotfix on `v3` is `v3.1`, the
next `v3.2`.

| Tag    | Commit    | Date       | What                    |
|--------|-----------|------------|-------------------------|
| `v3`   | `37775c9` | 2026-10-22 | release                 |
| `v3.1` | `aea6d65` | 2026-10-24 | hotfix (#69) on `v3`    |
| `v4`   | `77a90e1` | 2026-10-24 | release                 |
| `v5`   |           |            | next release            |

### Cutting a release

0. Run the pre-release check in
   [`results-snapshots.md`](results-snapshots.md), "Pre-release check
   against a copy of production". The "before" snapshot
   (`<live-release-tag>`) is the tag live now; the "after" one
   (`<candidate-branch-or-tag>`) is the candidate.
1. Open a PR `develop` → `master` and merge it with **Create a merge
   commit**. Never squash or rebase: `master` and `develop` must keep the
   same commits.
2. On GitHub, **Releases → Draft a new release**. Under "Choose a tag",
   type the new tag `vN` with `master` as the target; set "Previous tag"
   to the last release; click **Generate release notes**; publish.

### Deploying

On the server, `git pull` on `master`. The release's runbook lists any
extra steps (migrations, new `.env` values, rebuilds).

To roll back: `git checkout <previous tag>`, then recreate the containers
the release changed (`docker compose up -d --force-recreate <service>...`
from `baja-infra/`). If a phpBB container was among them, also
`docker compose restart nginx`; see the FAQ below. Go back to `master`
once the fix is released.

### Hotfixes

1. Branch from `master`: `git switch -c hotfix/<thing> origin/master`.
2. PR into `master`. `develop` is the default branch, so pick the base by
   hand (`gh pr create --base master`). Merge with a merge commit and tag
   it `vN.M` as above.
3. Open a back-merge PR `master` → `develop`
   (`gh pr create --base develop --head master`) and merge it with a merge
   commit.

**WARNING: on a back-merge PR (head = `master`), never click "Delete
branch" after merging. It deletes `master`.** It happened on 2026-10-24;
`master` was restored from PR #74.

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
