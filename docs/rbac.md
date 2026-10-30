# Programs and RBAC

Who may do what, and where. Phases 1 and 2 of "Plan: programs and RBAC"
(BAJAAPP-11, BAJAAPP-12). **Nothing in the site uses this yet.** Every page
still checks the legacy permission codes through `Session::hasPermission`.
Switching the call sites over is phase 3.

## Programs (BAJAAPP-11)

Every event belongs to a program, stored in `evento.programa_id`:

| programa_id | Name | Under |
|---|---|---|
| BAJA | Baja SAE | |
| AERO | AeroDesign | |
| AERO_FORUM | Fórum AeroDesign | AERO |
| FORMULA | Fórmula SAE | |
| ELETROQUAD | Eletroquad | |
| H2 | H2 Challenge | |

You choose the program on `juiz/evento.php`. A clone copies it from the
reference event. **No code works out a program from an event code.** The
one exception was `PropelMigration_1793070000`, which filled the column once
for the events that already existed (BR/SE/SU/NE → BAJA, AR → AERO,
FA → AERO_FORUM, FO → FORMULA, EQ → ELETROQUAD, H2 → H2). It refuses to run
if it finds a suffix it doesn't know.

`Baja\Programa\ProgramaResolver` answers "which program is this event in?"
and "which programs are above or below this one?".

## The scope tree

Roles are given on a **node**:

```
platform                      ScopeNode::platform()
  programa                    ScopeNode::programa('AERO')       (programs can nest)
    evento                    ScopeNode::evento('26BR')
      prova                   ScopeNode::prova('26BR', 'END')   stored as '26BR/END'
      fila                    ScopeNode::fila('26BR', 3)        stored as '26BR/3'
```

`Baja\Rbac\Scope\ScopeTree` builds the tree from the real tables:
`prova.evento_id`, `fila.evento_id`, `evento.programa_id` and
`programa.parent_id`. A grant on a node reaches every node below it, and
never a node above it or beside it.

If a node isn't in the tables (an event that was deleted, a misspelled id, a
loop in `programa.parent_id`), it is **unknown**: every check on it says no,
and a warning is logged. Ids must match exactly, case included.

To add a new kind of node, add a `ScopeType` case and give `ScopeTree` a
`ParentFinder` for it. Grants are stored as plain type + id, so they don't
change.

## Permissions

`Baja\Rbac\Permission\Permission` lists them all. Each one names the node
types it **applies to**, meaning the kinds of node it makes sense to ask
about:

| Permission | Applies to | Replaces (legacy) |
|---|---|---|
| evento.manage | programa (create events), evento | `admin` on the event pages |
| prova.configure | evento (add provas), prova | `admin` on the prova pages |
| prova.enter_scores | prova | `<EVT>_<PROVA>` |
| resultado.edit | evento | `admin` on the resultado pages |
| fila.manage | evento (create queues), fila | `<EVT>_FILA_<id>_ADMIN`, `admin` |
| timer.manage | evento | `<EVT>_TIMER_ADMIN` |
| timer.operate | evento | `<EVT>_TIMER_<id>` (per timer before; timers are not nodes) |
| certificado.manage | programa, evento | `certificados` (global before) |
| premiacao.manage | evento | `<EVT>_PREMIACAO`, `admin` |
| equipe.manage | evento | `admin` on the team pages |
| notificacao.send | evento | `admin` on `admin_push.php` |
| contagem.count | prova | `<EVT>_<PROVA>_CONT` |
| contagem.pit_log | prova | `<EVT>_<PROVA>_BOX` |
| contagem.control | prova | `<EVT>_<PROVA>` as race controller, `admin` |
| role.grant | every type | `admin` on the user pages |
| role.manage | platform only | (new) edit role definitions, give system roles |

`<EVT>_TIMER_<id>` gave one timer of an event. `timer.operate` is given on
the whole event instead: a timer operator may run every timer of their
event. This is decided, not an oversight. Timers aren't scope nodes, and
making them nodes for this one code isn't worth it.

Two legacy codes have no permission: `index`, which only lets you reach the
judge landing page, and `<EVT>_FILA_<id>_<equipe>`, a team's own access to
its queue. A team isn't staff, so that code isn't a role.

## Roles

A role is a named set of permissions, plus the node types it can be given
on. These eight roles come from the migration:

| Role | Given on | Carries |
|---|---|---|
| platform_admin (system) | platform | everything |
| program_manager | programa | everything except role.manage |
| event_manager | evento | everything except certificado.manage and role.manage |
| head_judge | prova | prova.enter_scores, contagem.control, role.grant |
| judge | prova | prova.enter_scores, contagem.control |
| queue_manager | fila | fila.manage |
| timer_manager | evento | timer.manage, timer.operate |
| certificate_manager | programa | certificado.manage |

Only `platform_admin` is a system role. If the others were system roles,
nobody below the platform could hand them on, and a head judge couldn't add a
judge to their prova.

The migration also gave `platform_admin` to every user whose legacy
permissions included `admin`. It gave nothing to anyone else.

## The rules

**Checking** (`Authorizer::can($user, $permission, $node)`) says yes only
when all of these hold:

1. the permission applies to the node's type;
2. the node exists;
3. the user has an active grant (not revoked, not past `valid_until`) on the
   node or on any node above it;
4. that grant's role carries the permission, and the role is allowed on the
   type of node the grant is on.

Otherwise it says no. Nobody gets special treatment: a platform admin is just
someone holding `platform_admin` on the platform node.

**Granting** (`RoleGranting::grant`) is allowed only when:

1. the node exists;
2. the role is allowed on that type of node;
3. the granter has role.grant on the node or above it;
4. the granter holds every permission the role carries, on that node. That
   prevents escalation: you can only hand out what you hold yourself;
5. for a system role, the granter has role.manage.

The new grant also **never outlives the granter's own right to grant
there**. See "Expiry" below.

**Revoking** checks the same five rules. Anyone who could have given a
grant can take it away.

Every grant and every revoke writes one `role_audit` row in the same
transaction. A refused attempt writes nothing and logs a warning.

**Editing a role** (`RoleEditor::save`) needs role.manage and writes a
`role_audit` row with the role before and after.

Grants are never deleted. A revoke fills in `revoked_at` and `revoked_by`.

`role_audit` is append-only, and MySQL itself enforces it: two triggers,
`role_audit_no_update` and `role_audit_no_delete`, refuse every UPDATE and
DELETE on it with an error (SQLSTATE 45000). Our code doesn't try either, and
`tests/Unit/Rbac/AuditIsAppendOnlyTest.php` checks that.
`tests/Rbac/Db/AuditIsAppendOnlyInMysqlTest.php` checks that MySQL refuses
both.

The triggers don't stop TRUNCATE or DROP TABLE, which fire no triggers, or
someone dropping the triggers first. Each of those needs a privilege the
application's MySQL account has today (ALL on `baja_resultados`). Taking
those privileges away from it would be a separate change.

## Expiry

A grant on an event, or on one of its provas or queues, ends **7 days after
the event's last day**, at 23:59:59. That's the default when the granter
doesn't give an end. You can change the 7 with the `config` key
`rbac.dias_apos_evento`.

The last day is `evento.data_fim`, a DATE set on `juiz/evento.php` (along
with `data_inicio`, the first day). When an event has no `data_fim`, the
grant is **refused** unless the granter gives `valid_until` explicitly.
Guessing would be wrong both ways: too early locks judges out during the
event, and no end at all leaves access open forever.

`evento.data` stays the free text people read ("20 a 24 de abril de 2022").
Code never reads it. The migration that added the two date columns
(`PropelMigration_1793090000`) read it once, for the events that already
existed. It filled the dates where the text was exactly a date and printed
the events it couldn't read.

Grants on a program or on the platform have no default end of their own.

**A grant never outlives the grant that allowed it.** The granter's limit
is the end of their *covering* grant: the latest-ending of their active
grants that carry role.grant on the node or above it. If one of those grants
has no end, as with a platform admin, there is no limit. Then:

- an explicit `valid_until` after the limit is **refused**
  (`VALID_UNTIL_AFTER_GRANTER`), and the message says until when they may
  grant. It is never quietly shortened, because the person asked for that
  date;
- the default end is brought forward to the limit when it would be later.
  So is a grant that has no default end (one on a program). Nobody asked for
  those dates, so taking the latest allowed one surprises no one.

Revoking the covering grant later doesn't end grants made with it. They keep
their own `valid_until`, which is never later than the covering grant's end.

## Caching

Roles and grants are read once per `RbacServices` and kept nowhere else (no
Redis). `RbacServices::forRequest()` keeps one set for the length of one PHP
request, so a grant made or revoked shows on the next request. A
long-running script should call `RbacServices::create()` whenever it needs
fresh answers.

## Code

```
src/Baja/Programa/       ProgramaTree, ProgramaResolver
src/Baja/Rbac/
  Scope/                 ScopeType, ScopeNode, ScopeTree, ParentFinder, Parents/
  Permission/            Permission (the catalogue)
  Role/                  Role, RoleRepository
  Grant/                 Grant, GrantRepository
  Authorization/         Authorizer
  Delegation/            RoleGranting, RoleEditor, GrantRefused
  Expiry/                GrantExpiry
  Audit/                 AuditLog, DatabaseAuditLog
  RbacServices.php       wires one request's set
```

The tables (`programa`, `role`, `role_permission`, `user_role`,
`role_audit`) and `evento.data_inicio` / `data_fim` are in `schema.xml`, with
comments. The `role_audit` triggers are in `PropelMigration_1793100000`,
which explains them; `schema.xml` can't describe triggers. The RBAC tables are read
with plain SQL, not with generated models.

## Tests

```
docker compose exec baja-app vendor/bin/phpunit --testsuite unit --filter 'Programa|Rbac'
docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group programa
docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --group rbac
```

The database tests create made-up events (96BR, 96SE, 96AR, 96FA, 97BR),
users (`zzrbac_*`) and roles (`zz_*`), and remove them afterwards. The
exception is their `role_audit` rows, which the triggers keep. Those rows
name users and roles that no longer exist, and `AUTO_INCREMENT` never reuses
those ids, so no later test reads them as its own. CI runs both groups.
