# Programs and RBAC

Who may do what, and where. "Plan: programs and RBAC": phases 1 and 2
(BAJAAPP-11, BAJAAPP-12) built programs and the RBAC core; phase 3
(BAJAAPP-76) switched every staff page over to it. **Every access check now
goes through `Baja\Rbac\Authorization`.** The legacy codes in
`user.permissions` are read by nothing but the migration and the teams'
own queue codes. See [The switch-over](#the-switch-over-bajaapp-76).

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
| premiacao.manage | evento | `admin` on the prize pages, and seeing unpublished prizes |
| premiacao.view | evento | `<EVT>_PREMIACAO` (added in phase 3) |
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

`<EVT>_PREMIACAO` let judges *read* the published prizes. Only `admin` saw
the drafts and edited prizes. Phase 3 kept the two apart: `premiacao.view`
for the first, `premiacao.manage` for the second.

## Roles

A role is a named set of permissions, plus the node types it can be given
on. The first eight come from the phase 2 migration, the last four from
phase 3's (one legacy code each):

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
| lap_counter | prova | contagem.count |
| pit_logger | prova | contagem.pit_log |
| timer_operator | evento | timer.operate |
| awards_viewer | evento | premiacao.view |

Phase 3 also added `premiacao.view` to platform_admin, program_manager and
event_manager, which carried `premiacao.manage`, so they still see what
they saw.

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

**Granting by authority** (`RoleGranting::grantByAuthority` and
`revokeByAuthority`) is a second, narrower door. A page uses it when it hands
out one fixed role as part of its own work. The only user today is the lap
counting's "Usuários" page: the race controller (judge role, no role.grant)
adds lap counters and pit loggers, as they could before RBAC
(`Contagem\Access\ContagemRoles`). Rules 3 and 4 become "the granter holds
`$authority` (contagem.control) on the node". Rules 1, 2 and 5 still apply,
and so do the expiry cap and the audit row. A role that carries the
authority itself or role.grant is refused (`ESCALATION`), so a controller
can add counters but never another controller. The revoke takes away only
grants of the role the page names.

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

## The switch-over (BAJAAPP-76)

Phase 3 moved every staff check from the legacy codes to RBAC. A page asks
`Authorization::can($user, Permission::X, $node)` (or `currentUserCan`, or
`require`, which ends the request with the usual refusal). The node comes
from the request:

- the event is `$_SERVER['REDIRECT_EVENT']`, from `/26BR/...` (or picked by
  `src/bootstrap.php`): `Authorization::currentEvent()`;
- a prova of it is `Authorization::prova($_REQUEST['p'])`;
- a queue, a program or another event is built with `ScopeNode::...` from
  the page's own data.

A few questions are not "one permission on one node":

| Method | Answers | Used by |
|---|---|---|
| `hasAnyGrantInEvent($user, $evento)` | any active grant on the event, on one of its provas or queues, or on a node above it | the judge start page, the lap counting door |
| `isStaff($user)` | any active grant anywhere | the results cache cookie |
| `canAnywhere($user, $p)` / `nodesGiving($user, $p)` | the permission held on some node / the nodes where it is given | pages that list what you manage: events, users |
| `canEverywhere($user, $p)` | the permission on every top-level program, so on every event | the certificate pages |

### Every call site

Before the switch-over, `Session::hasPermission($code)` prefixed `$code`
with the request's event (except `index` and `certificados`), then answered
yes for that code or for `admin`. `$user->hasPermission($code)` checked the
exact code, with no `admin` shortcut. Both are gone. In the table, `EVT` is
the request's event and `P` the prova of the page.

**Judge area**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `juiz/index.php` (door) | `permissionCheck('index')` | `index` | `hasAnyGrantInEvent(EVT)` |
| `juiz/contagem.php` (door) | `permissionCheck('index')` | `index` | `hasAnyGrantInEvent(EVT)`, then `ContagemAccess::mayEnter` |
| `juiz/index.php`, list of provas | `hasPermission($prova)` | `EVT_P` | prova.enter_scores @ prova EVT/P |
| `juiz/index.php`, lap counted provas | `ContagemAccess::mayEnter` | see lap counting | see lap counting |
| `juiz/index.php`, "Premiações" | `hasPermission('PREMIACAO')` | `EVT_PREMIACAO` | premiacao.view @ evento EVT |
| `juiz/index.php`, "Certificados" | `Acesso::permitido()` | `certificados` | certificado.manage on every top program |
| `juiz/index.php`, "Timers" | `hasPermission('TIMER_ADMIN')` or any code starting `EVT_TIMER_` | `EVT_TIMER_ADMIN`, `EVT_TIMER_<id>` | timer.manage or timer.operate @ evento EVT |
| `juiz/index.php`, "Administração" | `hasPermission('admin')` | `admin` | evento.manage @ evento EVT |
| `entry.php`, `save_entry.php`, `rolling_entry.php`, `save_rolling_entry.php`, `tournament_entry.php`, `dashboard.php` | `permissionCheck($_page)` | `EVT_P` | prova.enter_scores @ prova EVT/P |

**Prizes**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `premiacoes.php`, `premiacao_view.php`, `premiacao_pdf.php` | `permissionCheck('PREMIACAO')` | `EVT_PREMIACAO` | premiacao.view @ evento EVT |
| the same three, drafts (`$isAdmin`) | `$user->hasPermission('admin')` | `admin` | premiacao.manage @ evento EVT |
| `admin_premiacoes.php`, `premiacao_admin.php` | `permissionCheck('admin')` | `admin` | premiacao.manage @ evento EVT |

**Timers**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `admin_timers.php`, `timer_admin.php`, `timer_tts.php` | `permissionCheck('TIMER_ADMIN')` | `EVT_TIMER_ADMIN` | timer.manage @ evento EVT |
| `admin_timers.php`, "Voltar" target | `$user->hasPermission('admin')` | `admin` | evento.manage @ evento EVT |
| `TimerAcesso::podeConfigurar` (`timer.php`) | `admin` or `<ev>_TIMER_ADMIN` in the row | `admin`, `EVT_TIMER_ADMIN` | timer.manage @ evento of the timer |
| `TimerAcesso::podeUsar` (`timer.php`, `timer_action.php`, by id) | the above, or `<ev>_TIMER_<id>` | `EVT_TIMER_<id>` | the above, or timer.operate @ evento of the timer |

Opening a timer by its access or view key (`?k=`) needs no session and is
unchanged.

**Lap counting** (`Contagem\Access\ContagemAccess`). Every one also requires
the prova to be in the request's event, as before.

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `isController` (`contagem.php` hub, `requireController` in `contagem_controle.php`, `contagem_revisao.php`, `contagem_fontes.php`, `contagem_contadores.php`, `contagem_exportar.php`, `Review\Pagina`) | `hasPermission($prova)` | `EVT_P` | contagem.control @ prova |
| `isCounter` | `hasPermission($prova . '_CONT')` | `EVT_P_CONT` | contagem.count @ prova |
| `canUsePitLog` (`contagem_box.php`, `contagem_dispositivo.php`) | controller, or `hasPermission($prova . '_BOX')` | `EVT_P_BOX` | controller, or contagem.pit_log @ prova |
| `canCountOn` (`contagem_contar.php`, `contagem_dispositivo.php`) | controller, or a link in `contagem_fonte_usuario` | `EVT_P` | contagem.control @ prova, or the link (unchanged) |
| `mayEnter` | controller, counter or pit log | | the three above |
| `userMayStillLogStops` (upload endpoint, `Sync\SyncHandler`; no session) | `admin`, `<ev>_<P>` or `<ev>_<P>_BOX` in the row | `admin`, `EVT_P`, `EVT_P_BOX` | contagem.control or contagem.pit_log @ prova, for the device's user |
| `contagem_controle.php`, wiping a started race | `hasPermission('admin')` | `admin` | prova.configure @ prova |
| `CounterLinks::link` / `unlink` (writes) | added / removed `EVT_P_CONT` | | gives / revokes lap_counter @ prova, by the controller's contagem.control (`grantByAuthority`) |
| `PitLoggers::add` / `remove` / `listForProva` | added / removed / searched `EVT_P_BOX` | | gives / revokes / lists pit_logger @ prova, the same way |
| `scripts/contagem-simular-apoio.php` (dev only) | added `EVT_P_BOX` to the simulation user | | gives pit_logger @ prova, straight to the tables |

**Certificates**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `Certificado\Insercao\Acesso::exigir` (`certificados.php`, `certificados_lote.php`, `certificados_nome.php`, `certificados_busca.php`, `certificados_requerimentos.php`, `lotes.php`, `lote.php`) and `::permitido` | `hasPermission('certificados')` (global) | `certificados` (`Acesso::PERMISSAO`) | `Acesso::usuarioPode`: certificado.manage on every top-level program |
| `Certificado\Requerimento\Destinatarios::usuariosComPermissao` (who gets the reports) | `certificados` or `admin` in the row | `certificados`, `admin` | `Acesso::usuarioPode`, per user |

**Queues**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `Fila::getFilasUsuario` (`fila/filapicker.php`, `fila/filaadmin.php`, `fila/senha.php`), "ADMIN" entries | codes containing `FILA` in the row | `EVT_FILA_<n>_ADMIN` | fila.manage @ fila, for every queue of an unfinished event |
| `Fila::checkPermissaoFila` (`fila/filaaction.php`), manager half | `$user->hasPermission(EVT_FILA_<n>_ADMIN)` | `EVT_FILA_<n>_ADMIN` | fila.manage @ fila |
| `Fila::getFilasUsuario` / `checkPermissaoFila`, team half | the team's code in the row | `EVT_FILA_<n>_<team>` | **unchanged** (not a role; teams package) |
| `admin_filas.php` | `permissionCheck('admin')` | `admin` | fila.manage @ evento EVT |
| `juiz/fila.php` (a queue's settings) | `permissionCheck('admin')` | `admin` | fila.manage @ evento EVT; creating one also @ the event in the form |
| `admin_fila_acesso.php` (teams' codes in bulk) | `permissionCheck('admin')` | `admin` | fila.manage on an event or above; each queue touched: fila.manage @ fila |

**Admin pages**

| Call site | Legacy check | Code | Now |
|---|---|---|---|
| `admin.php` | `permissionCheck('admin')` | `admin` | evento.manage @ evento EVT |
| `admin_eventos.php` | `permissionCheck('admin')` | `admin` | evento.manage somewhere; lists the events where it holds |
| `evento.php` | `permissionCheck('admin')` | `admin` | `?id=`: evento.manage @ that event; `?novo`: evento.manage somewhere; saving: evento.manage @ the program chosen |
| `clonar_evento.php` | `permissionCheck('admin')` | `admin` | evento.manage somewhere; cloning: evento.manage @ the reference event's program |
| `ajuda_menu.php` | `permissionCheck('admin')` | `admin` | evento.manage somewhere |
| `admin_equipes.php`, `equipe.php` | `permissionCheck('admin')` | `admin` | equipe.manage @ evento EVT |
| `admin_provas.php`, `ajuda_prova.php`, `prova.php?nova` | `permissionCheck('admin')` | `admin` | prova.configure @ evento EVT |
| `prova.php?id=P`, `prova_log.php`, `torneio.php` | `permissionCheck('admin')` | `admin` | prova.configure @ prova EVT/P |
| `admin_resultados.php`, `resultado.php`, `ajuda_resultado.php` | `permissionCheck('admin')` | `admin` | resultado.edit @ evento EVT |
| `admin_push.php` | `permissionCheck('admin')` | `admin` | notificacao.send @ evento EVT |
| `admin_usuarios.php`, `admin_usuario.php` | `permissionCheck('admin')` | `admin` | role.grant somewhere (the page offers only the places covered) |
| `admin_users.php`, `user.php` | `permissionCheck('admin')` | `admin` | role.grant @ evento EVT (read only now) |
| `Auth\StaffCookie::isStaff` (`src/bootstrap.php`) | `index` or `admin` in the row | `index`, `admin` | `isStaff`: any active grant |

**Out of scope, unchanged:**

- a team's own queue code, `EVT_FILA_<n>_<team>`, read and written by
  `Fila` and `admin_fila_acesso.php`. It belongs to the teams package. A
  request for team "ADMIN" never matches the old `_ADMIN` code;
- `resultado.authorized_users`, who sees a resultado past its spoilers
  (`Results\ViewerContext`). It's a list of usernames per resultado, a
  separate concept.

### From the legacy codes

`Baja\Rbac\Legacy\LegacyCode` is the table. The migration uses it, and so do
the tests that say "a user with code X":

| Legacy code | Becomes |
|---|---|
| `admin` | platform_admin on the platform (already given in phase 2) |
| `certificados` | certificate_manager on every top-level program |
| `index` | nothing (dropped on purpose) |
| `EVT_PREMIACAO` | awards_viewer on the event |
| `EVT_TIMER_ADMIN` | timer_manager on the event |
| `EVT_TIMER_<id>` | timer_operator on the event |
| `EVT_FILA_<n>_ADMIN` | queue_manager on queue n |
| `EVT_FILA_<n>_<team>` | nothing: kept in `user.permissions` |
| `EVT_P_CONT` | lap_counter on prova P |
| `EVT_P_BOX` | pit_logger on prova P |
| `EVT_P` | judge on prova P (score entry and race control, as the code was) |
| anything else, or one naming an event, prova, queue or timer that doesn't exist | nothing: **listed** as unmapped |

### The data migration

`PropelMigration_1793110000` adds the four roles and `premiacao.view`, then
turns every legacy code into a grant, once (`LegacyGrantPlan`):

- `granted_by` NULL (the migration), `granted_at` now, one `role_audit` row
  per grant whose details name the legacy code;
- a grant on an event, prova or queue ends at 23:59:59 on `data_fim` + the
  configured days (`rbac.dias_apos_evento`, 7), as `GrantExpiry` would end it;
- a grant on an event with no `data_fim` gets **no end**, and the migration
  lists the event. Set the date on `juiz/evento.php`, then shorten the grants;
- a grant whose end has already passed (an event that ended earlier) is still
  written, so the record stays, and is listed. It gives nothing. **So judges
  of events that ended more than 7 days ago lose access to those events.**
  That's the expiry rule, applied to the old codes;
- a grant the user already holds (the phase 2 platform admins) is not
  written again. Running the plan twice writes nothing the second time;
- codes that map to nothing are listed with their user and a reason.

It prints all of this when it runs. Read the output and act on the lists.

The dev seed (`12-rbac-switchover.sql`) gives the seed users their grants
**with no end**. 26BR ended in March 2026, and with real ends the dev stack's
judges would be locked out.

### Decided differences

`tests/Rbac/Db/CallSiteParityTest.php` asks every call site, for every kind
of legacy code, at matching and non-matching places, and expects the old
answer (`tests/Support/LegacyOracle.php`, a copy of every removed check).
These differences are deliberate. The test lists each one and checks both
the old and the new answer:

1. **The judge start page** opens for anyone with a grant in the event or
   above. Before, `index` opened it in any event (with nothing on it), and a
   user holding only `index` got in.
2. **The staff cookie** follows a grant. `index` alone made you staff.
3. **A timer operator runs every timer of their event**, not one (timers
   aren't scope nodes; decided in phase 2).
4. **A platform admin manages every queue** on the queue site. Before,
   `admin` wasn't enough there: `Fila` read only the queue's own code.

And a few that come from asking about real places:

- a place that doesn't exist answers no. `entry.php?p=XYZ` no longer gets
  past the check for an admin, and a team's code can't be added for a queue
  that doesn't exist;
- a legacy code for a timer, queue or prova that no longer exists maps to
  nothing (and is listed);
- user pages no longer delete user rows (grants and the audit trail refer to
  them).

Program and event managers are new roles with no legacy counterpart. The
event pages (`admin_eventos.php`, `evento.php`, `clonar_evento.php`) work
for them, limited to their program or event.

### The role page

`juiz/admin_usuario.php` replaced editing the raw permission list. It's a
stopgap until the role screens of the new site (BAJAAPP-78). For anyone with
role.grant somewhere, it shows:

- the user's active grants: role, place, end ("sem fim" when none), who gave
  it ("migração" for the migration), and "Remover" where the viewer may take
  it away;
- a form to give a role: the role, the place (only places the viewer covers,
  filtered to where the role may be given), and an optional end date. Left
  empty, the RBAC core decides (7 days after the event, never after the
  viewer's own right to grant there);
- a team's queue codes, read only, with a link to `admin_fila_acesso.php`.

Every change goes through `RoleGranting::grant` / `revoke`, with the form's
CSRF token. The delegation rules, the expiry cap and the audit trail apply,
and a refusal is shown in Portuguese (`Juiz\Papeis`). `admin_users.php` and
`user.php` (the per-event pages) became read only and link to it.
`admin_usuarios.php` creates a user row holding nothing.

### Rolling back

`user.permissions` is left exactly as it was, for one release.
Deploying the previous release puts every page back on the legacy codes,
with the access everyone had before the switch-over. What is lost then:

- roles given after the switch-over (on the role page, or counters and pit
  loggers added by a controller): the old code reads only the column. Before
  rolling back, list them
  (`SELECT * FROM user_role WHERE granted_by IS NOT NULL AND revoked_at IS NULL`)
  and add the matching codes on the old pages afterwards;
- revocations after the switch-over: the old codes are still in the column.

The grants can stay in the database during a rollback; nothing in the old
release reads them. `PropelMigration_1793110000::getDownSQL()` removes the
migration's grants and the four roles. That's only needed to undo the
migration completely, never to roll the code back.

After the release that proves the switch-over, drop the column's staff codes
(everything but the teams' queue codes), or the column itself once the teams
package has moved those.

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
  Authorization.php      the one door the pages use (phase 3)
  GrantableNodes.php     the places a user may give roles on, for the role page
  Scope/                 ScopeType, ScopeNode, ScopeTree, ParentFinder, Parents/
  Permission/            Permission (the catalogue)
  Role/                  Role, RoleRepository
  Grant/                 Grant, GrantRepository
  Authorization/         Authorizer
  Delegation/            RoleGranting, RoleEditor, GrantRefused
  Expiry/                GrantExpiry
  Audit/                 AuditLog, DatabaseAuditLog
  Legacy/                LegacyCode (code -> grant), LegacyGrantPlan (the migration's data step)
  RbacServices.php       wires one request's set
src/Baja/Contagem/Access/ContagemRoles.php   the counter and pit logger roles, by the controller's authority
src/Baja/Juiz/Papeis.php                     refusals in Portuguese, for the role page
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
docker compose exec -e BAJA_TEST_DB=1 baja-app vendor/bin/phpunit --testsuite db --filter HttpJudgePagesTest
```

The last one needs the whole dev stack (it logs in at the forum and asks
nginx for the pages); CI doesn't run it. Neither does CI run the HTTP
harnesses that give their users grants (`tests/csrf/http-test.sh`,
`tests/certificado/insercao-http-test.sh`, `tests/results-caching/http-test.sh`,
`tests/smoke-test.sh`). Run those before merging a change to access.

The database tests create made-up events (96BR, 96SE, 96AR, 96FA, 97BR),
users (`zzrbac_*`) and roles (`zz_*`), and remove them afterwards. Tests that
need "a user holding legacy code X" give them the grant the migration makes
of X (`tests/Support/LegacyGrants.php`, the same `LegacyCode` table), so each
of them is also a small parity check. The
exception is their `role_audit` rows, which the triggers keep. Those rows
name users and roles that no longer exist, and `AUTO_INCREMENT` never reuses
those ids, so no later test reads them as its own. CI runs both groups.
