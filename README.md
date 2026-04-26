# Baja SAE Brasil Online

Platform monorepo for the Baja SAE Brasil web infrastructure
(<https://bajasaebrasil.net>).

Repo created by tiagomartines11 circa 2017–2018; in continuous use
since with various upgrades. The major Apr-2026 change consolidated
the previously-separate PHP app, infra, and Next.js frontend into
this single repository.

## Layout

- [`baja-php/`](baja-php/) — PHP application: judges, queue,
  results, certificates. The legacy core that powers the
  `*.baja.local` subdomains in production.
- [`baja-js/`](baja-js/) — Next.js frontend. Currently the
  recruitment site; future home of the migrated UI.
- [`baja-infra/`](baja-infra/) — Docker Compose, nginx configs,
  MySQL init scripts, container Dockerfiles. Boots the full local
  dev stack with one command.
- [`docs/`](docs/) — Cross-cutting documentation (workflow,
  deployment, runbooks).

Per-language documentation lives next to its code:
- PHP-specific: [`baja-php/docs/`](baja-php/docs/)
- JS-specific: [`baja-js/README.md`](baja-js/README.md)

## Getting started

> **TODO:** human to fill in. The short version is
> `cd baja-infra && docker compose up -d`, but the full local-dev
> setup (hosts file entries, dev DB credentials, smoke tests)
> deserves a real walkthrough here.

See [`docs/dev-workflow.md`](docs/dev-workflow.md) for the
branching, review, and deploy model the team has agreed on.

## Contributing

The code is highly incomplete in places — there's legacy code
sitting alongside the Propel-based revamp, and the PHP/JS split is
still in progress. Feel free to ask for help and/or contribute :)

## License

See [`LICENSE`](LICENSE).
