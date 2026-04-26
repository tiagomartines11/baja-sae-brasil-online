# Baja SAE Brasil Online

The web infrastructure for [Baja SAE Brasil](https://bajasaebrasil.net). This codebase runs:
  - Scoring system
  - Virtual queue system
  - Participation certificates
  - Discussion Forum
  - Discussion Forum for Fórmula SAE BRASIL

It's a real-world platform that real teams use during real competitions. It's also a work in progress — you'll find legacy code alongside modern code, and we're actively migrating the whole thing into a containerized stack with a modern frontend. Pull requests welcome.

## What's in here

This is a monorepo with three concerns:

- **`baja-php/`** — PHP application that powers scoring system, virtual queues, and participation certificate generation. Uses Propel ORM. This is most of the production-facing functionality today.
- **`baja-js/`** — Next.js frontend. Currently the public landing page; future home for new user-facing features as we migrate out of PHP.
- **`baja-infra/`** — Docker Compose, nginx, MySQL, phpBB Dockerfiles, and the rest of the operational glue. One command spins up the whole stack locally.
- **`docs/`** — Architecture notes, dev workflow, anything that crosses the above directories.

## Getting started

You need [Docker](https://docs.docker.com/get-docker/) (with Docker Compose v2) and a way to edit `/etc/hosts`.

```bash
git clone https://github.com/tiagomartines11/baja-sae-brasil-online.git
cd baja-sae-brasil-online/baja-infra

cp .env.example .env       # dev defaults work; edit if needed
docker compose up -d
```

Add the local domains to `/etc/hosts` (or your platform's equivalent):

```
127.0.0.1  baja.local forum.baja.local juiz.baja.local fila.baja.local resultados.baja.local certificado.baja.local forum.formula.local
```

Then visit:

- `http://baja.local/` — public landing page (Next.js)
- `http://forum.baja.local/` — phpBB Baja
- `http://juiz.baja.local/` — judges dashboard
- `http://fila.baja.local/` — competition queue management
- `http://resultados.baja.local/` — public results
- `http://certificado.baja.local/` — certificate generator

Test login: User `superadmin` has admin permission to the baja-php app, other seeded users (`juiz1`, `juiz2`, `capitao1`, `capitao2`, `equipe1`, `equipe2`, `orientador1`, and `orientador2`) can be used to test different permissions. All use  password `123456`. Don't ship that to prod.

## Contributing

The team uses a trunk-based workflow with a `develop` integration branch. See `docs/dev-workflow.md` for the full conventions: branch naming, PR review, deploy flow, and competition-week freeze policy.

## License

See [LICENSE](LICENSE).

---

Maintained by the Baja SAE Brasil organizing committee. Questions, PRs, or "I want to help but don't know where to start" → open an issue.