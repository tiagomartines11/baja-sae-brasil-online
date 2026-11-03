<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Propel\Runtime\Propel;

/**
 * The judge pages, through the REAL web server, after the switch-over to
 * RBAC (BAJAAPP-76).
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * CallSiteParityTest (tests/Rbac/Db) proves, check by check and in
 * process, that a user holding a migrated grant gets the same answer the
 * legacy code gave. This test opens the PAGES themselves, as a browser
 * does: log in at the forum, request the page from nginx, read what comes
 * back. It catches what an in-process test cannot: a page that forgot to
 * load a class, an import missing, a check on the wrong place.
 *
 * Two people (seeded accounts of the dev stack):
 *
 *   juiz1     a judge who, for the length of each test, holds the grant
 *             the migration made of one legacy code ($this->grant())
 *   equipe1   somebody with NO grant in the test event (they count laps at
 *             26BR, nowhere else)
 *
 * The made-up event 00NE (DbTestCase) gets, for these tests: a normal prova
 * ZZN, queue 1, timer 1, a published prize list and a draft one, on top of
 * the lap counted prova ZZL it already has.
 *
 * Needs the dev stack running: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class HttpJudgePagesTest extends DbTestCase
{
    use HttpBrowser {
        tearDown as private browserTearDown;
    }

    private const NOBODY = 'equipe1';
    private const REFUSAL = 'Você não tem acesso a essa página';

    protected function setUp(): void
    {
        parent::setUp();
        $this->executeSql(
            "INSERT INTO prova (evento_id, prova_id, nome, params) VALUES (?, 'ZZN', 'Prova normal de teste', ?)",
            [self::EVENTO_ID, json_encode(['type' => 'normal', 'inputs' => [['code' => 'NOTA', 'name' => 'Nota', 'type' => 'number', 'precision' => 0]]])]
        );
        $this->executeSql("INSERT INTO fila (evento_id, fila_id, nome) VALUES (?, 1, 'Fila de teste ZZ')", [self::EVENTO_ID]);
        $this->executeSql(
            "INSERT INTO timer (evento_id, timer_id, config, access_key, view_key) VALUES (?, 1, ?, 'ZZTA01', 'ZZTV01')",
            [self::EVENTO_ID, json_encode(['nome' => 'Cronometro de teste ZZ'])]
        );
        $this->executeSql("INSERT INTO premiacao (premiacao_id, evento_id, nome, status) VALUES ('ZZP', ?, 'Premio publicado ZZ', 1)", [self::EVENTO_ID]);
        $this->executeSql("INSERT INTO premiacao (premiacao_id, evento_id, nome, status) VALUES ('ZZD', ?, 'Premio rascunho ZZ', 0)", [self::EVENTO_ID]);
    }

    protected function tearDown(): void
    {
        if (TestWorld::isBooted()) {
            // Grants the role page made during a test (only tests delete grants).
            Propel::getWriteConnection('resultados')->exec("DELETE FROM user_role WHERE scope_id = '00NE' OR scope_id LIKE '00NE/%'");
        }
        $this->browserTearDown();
    }

    // ------------------------------------------------------------------

    /**
     * The start page lists the prova the judge holds (legacy 00NE_ZZN, now
     * the judge role on 00NE/ZZN), and refuses somebody with nothing here.
     */
    public function testTheStartPage(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZN');

        $page = $this->get('/00NE/index.php', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertStringContainsString('dashboard.php?p=ZZN', $page['body']);
        $this->assertStringNotContainsString(self::REFUSAL, $page['body']);
        $this->assertStringNotContainsString('admin.php', $page['body'], 'no Administração link for a judge');

        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/index.php', $this->loginAndGetCookies(self::NOBODY))['body']);
    }

    /** Entering scores: the prova's list of teams and one team's form. */
    public function testTheEntryPages(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZN');
        $judge = $this->loginAndGetCookies(self::CONTROLLER);
        $nobody = $this->loginAndGetCookies(self::NOBODY);
        $car = TestWorld::CARS[0];

        foreach (['/00NE/dashboard.php?p=ZZN', "/00NE/entry.php?p=ZZN&t=$car"] as $page) {
            $asJudge = $this->get($page, $judge);
            $this->assertSame(200, $asJudge['status'], $page);
            $this->assertStringNotContainsString(self::REFUSAL, $asJudge['body'], $page);
            $this->assertStringContainsString(self::REFUSAL, $this->get($page, $nobody)['body'], $page);
        }
        // The judge of ZZN is not a judge of ZZL.
        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/dashboard.php?p=ZZL', $judge)['body']);
    }

    /**
     * Configuring a prova was 'admin' only, and still is: the judge of the
     * prova is refused, the platform admin (legacy 'admin') gets the form.
     */
    public function testTheProvaConfigurationPage(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZN');
        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/prova.php?id=ZZN', $this->loginAndGetCookies(self::CONTROLLER))['body']);

        $this->grant(self::CONTROLLER, 'admin');
        $page = $this->get('/00NE/prova.php?id=ZZN', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString(self::REFUSAL, $page['body']);
        $this->assertStringContainsString('Prova normal de teste', $page['body']);
    }

    /**
     * Queues: the queue manager (legacy 00NE_FILA_1_ADMIN) runs queue 1 on
     * the queue site; somebody else is sent to the queue picker. The
     * queue's settings in the judge area stay for admins.
     */
    public function testTheQueuePages(): void
    {
        $this->grant(self::CONTROLLER, '00NE_FILA_1_ADMIN');
        $manager = $this->loginAndGetCookies(self::CONTROLLER);

        $run = $this->request('GET', '/filaadmin.php?evento=00NE&fila=1', $manager, null, 'fila.baja.local');
        $this->assertSame(200, $run['status']);
        $this->assertStringContainsString('Fila de teste ZZ', $run['body']);

        $other = $this->request('GET', '/filaadmin.php?evento=00NE&fila=1', $this->loginAndGetCookies(self::NOBODY), null, 'fila.baja.local');
        $this->assertSame(302, $other['status']);
        $this->assertStringContainsString('filapicker.php', $other['location']);

        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/fila.php?id=1', $manager)['body'], 'settings are for admins');
    }

    /**
     * Timers: an operator (legacy 00NE_TIMER_1) opens the timer but not the
     * timers' admin page; a timer manager (00NE_TIMER_ADMIN) opens both;
     * somebody else neither.
     */
    public function testTheTimerPages(): void
    {
        $this->grant(self::CONTROLLER, '00NE_TIMER_1');
        $operator = $this->loginAndGetCookies(self::CONTROLLER);
        $timer = $this->get('/00NE/timer.php?id=1', $operator);
        $this->assertStringContainsString('Cronometro de teste ZZ', $timer['body']);
        $this->assertStringNotContainsString('sem permissão', $timer['body']);
        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/admin_timers.php', $operator)['body']);

        $nobody = $this->loginAndGetCookies(self::NOBODY);
        $this->assertStringContainsString('sem permissão', $this->get('/00NE/timer.php?id=1', $nobody)['body']);
        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/admin_timers.php', $nobody)['body']);

        $this->grant(self::CONTROLLER, '00NE_TIMER_ADMIN');
        $manager = $this->loginAndGetCookies(self::CONTROLLER);
        $admin = $this->get('/00NE/admin_timers.php', $manager);
        $this->assertSame(200, $admin['status']);
        $this->assertStringNotContainsString(self::REFUSAL, $admin['body']);
    }

    /** Lap counting: the controller (legacy 00NE_ZZL) gets the hub; somebody else the refusal. */
    public function testTheLapCountingEntry(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZL');
        $hub = $this->get('/00NE/contagem.php?p=ZZL', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertSame(200, $hub['status']);
        $this->assertStringNotContainsString(self::REFUSAL, $hub['body']);
        $this->assertStringContainsString('contagem_controle.php?p=ZZL', $hub['body']);

        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/contagem.php?p=ZZL', $this->loginAndGetCookies(self::NOBODY))['body']);
    }

    /**
     * Certificates: the holder of the legacy 'certificados' (now a
     * certificate manager on every top program) gets the form; a judge gets
     * the 403 page, as before.
     */
    public function testTheCertificatePages(): void
    {
        $this->grant(self::CONTROLLER, '00NE_ZZN');
        $judge = $this->get('/certificados.php', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertSame(403, $judge['status']);
        $this->assertStringContainsString('Você não tem acesso a esta página', $judge['body']);

        $this->grant(self::CONTROLLER, 'certificados');
        $manager = $this->get('/certificados.php', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertSame(200, $manager['status']);
        $this->assertStringContainsString('escolha o evento', $manager['body']);
    }

    /**
     * Prizes: the holder of the legacy 00NE_PREMIACAO sees the published
     * list, not the draft; the platform admin sees both; somebody else
     * nothing.
     */
    public function testThePrizePages(): void
    {
        $this->grant(self::CONTROLLER, '00NE_PREMIACAO');
        $viewer = $this->get('/00NE/premiacoes.php', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertStringContainsString('Premio publicado ZZ', $viewer['body']);
        $this->assertStringNotContainsString('Premio rascunho ZZ', $viewer['body']);

        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/premiacoes.php', $this->loginAndGetCookies(self::NOBODY))['body']);

        $this->grant(self::CONTROLLER, 'admin');
        $admin = $this->get('/00NE/premiacoes.php', $this->loginAndGetCookies(self::CONTROLLER));
        $this->assertStringContainsString('Premio rascunho ZZ', $admin['body']);
    }

    /**
     * The role page (admin_usuario.php), the stopgap that replaced editing
     * the raw permission list. The platform admin gives equipe1 the judge
     * role on 00NE/ZZN, with the form's token; the grant names who gave it
     * and ends a week after the event (31 Dec 2099 + 7 days). Then takes it
     * back. A form sent without the token changes nothing; a judge cannot
     * open the page at all.
     */
    public function testTheRolePageGivesAndTakesARoleThroughTheRules(): void
    {
        $nobodyId = (int) $this->fetchValue('SELECT user_id FROM user WHERE username = ?', [self::NOBODY]);
        $page = '/admin_usuario.php?id=' . $nobodyId;

        $this->grant(self::CONTROLLER, '00NE_ZZN');
        $this->assertStringContainsString(self::REFUSAL, $this->get($page, $this->loginAndGetCookies(self::CONTROLLER))['body']);

        $this->grant(self::CONTROLLER, 'admin');
        $admin = $this->loginAndGetCookies(self::CONTROLLER);
        $form = $this->get($page, $admin);
        $this->assertStringContainsString('value="prova:00NE/ZZN"', $form['body'], 'the admin may give roles on the prova');
        $this->assertStringNotContainsString('name="j[]"', $form['body'], 'no more raw permission checkboxes');

        $fields = ['role' => 'judge', 'node' => 'prova:00NE/ZZN', 'valid_until' => ''];
        $forged = $this->post("$page&act=grant", $admin, $fields);
        $this->assertNotSame(302, $forged['status'], 'refused without the token');
        $this->assertSame(0, (int) $this->fetchValue("SELECT COUNT(*) FROM user_role WHERE user_id = ? AND scope_id = '00NE/ZZN'", [$nobodyId]));

        $given = $this->post("$page&act=grant", $admin, $fields + ['_csrf' => $this->tokenIn($form['body'])]);
        $this->assertSame(302, $given['status']);
        $this->assertStringContainsString('ok=dado', $given['location']);
        $grantId = (int) $this->fetchValue("SELECT grant_id FROM user_role WHERE user_id = ? AND scope_id = '00NE/ZZN' AND revoked_at IS NULL", [$nobodyId]);
        $this->assertGreaterThan(0, $grantId);
        $controllerId = (int) $this->fetchValue('SELECT user_id FROM user WHERE username = ?', [self::CONTROLLER]);
        $this->assertSame($controllerId, (int) $this->fetchValue('SELECT granted_by FROM user_role WHERE grant_id = ?', [$grantId]));
        $this->assertSame('2100-01-07 23:59:59', $this->fetchValue('SELECT valid_until FROM user_role WHERE grant_id = ?', [$grantId]));
        $this->assertSame(1, (int) $this->fetchValue("SELECT COUNT(*) FROM role_audit WHERE grant_id = ? AND action = 'grant'", [$grantId]));

        // equipe1 is now a judge of ZZN: the entry page opens for them.
        $this->assertStringNotContainsString(self::REFUSAL, $this->get('/00NE/dashboard.php?p=ZZN', $this->loginAndGetCookies(self::NOBODY))['body']);

        $list = $this->get($page, $admin);
        $this->assertStringContainsString('prova 00NE/ZZN', $list['body']);
        $taken = $this->post("$page&act=revoke", $admin, ['grant_id' => (string) $grantId, '_csrf' => $this->tokenIn($list['body'])]);
        $this->assertStringContainsString('ok=removido', $taken['location']);
        $this->assertSame($controllerId, (int) $this->fetchValue('SELECT revoked_by FROM user_role WHERE grant_id = ?', [$grantId]));
        $this->assertStringContainsString(self::REFUSAL, $this->get('/00NE/dashboard.php?p=ZZN', $this->loginAndGetCookies(self::NOBODY))['body']);
    }

    /**
     * The "Dar um papel" form is laid out like the other admin forms
     * (BAJAAPP-92), evento.php being the reference.
     *
     * WHAT "LAID OUT LIKE" MEANS HERE
     *
     * An admin form in the judge area is a table with the "tablesorter"
     * class (the site's blue theme styles it). Its header row is a title;
     * then one row per field, with the field's name in the LEFT cell and the
     * thing you type or pick in the RIGHT cell; then a footer row holding
     * the submit button. A message (an error, "saved") is a row of its own
     * that spans both cells.
     *
     * The test reads both pages as a browser gets them, finds the table of
     * each form, and describes it the same way (layoutOf below). Both must
     * have that shape. It also checks the role page brings no look of its
     * own: no <style> block, none of the CSS classes it used to invent.
     */
    public function testTheRoleFormIsLaidOutLikeTheEventForm(): void
    {
        $this->grant(self::CONTROLLER, 'admin');
        $admin = $this->loginAndGetCookies(self::CONTROLLER);
        $nobodyId = (int) $this->fetchValue('SELECT user_id FROM user WHERE username = ?', [self::NOBODY]);

        $evento = $this->layoutOf($this->get('/00NE/evento.php?id=00NE', $admin)['body'], 'evento.php?id=');
        $papel = $this->layoutOf($this->get('/admin_usuario.php?id=' . $nobodyId, $admin)['body'], 'act=grant');

        foreach (['evento.php' => $evento, 'admin_usuario.php' => $papel] as $page => $layout) {
            $this->assertTrue($layout['tablesorter'], "$page: the form's table has the tablesorter class");
            $this->assertTrue($layout['header'], "$page: a header row");
            $this->assertTrue($layout['submitInFooter'], "$page: the submit button is in the footer row");
            $this->assertNotEmpty($layout['fields'], "$page: at least one field");
            $this->assertSame([], $layout['otherRows'], "$page: every row is a field (label, control) or a message spanning both");
        }
        // The role form's three fields, each label in the left cell and its
        // control in the right one.
        $this->assertSame(
            ['Papel' => 'select', 'Onde' => 'select', 'Até (opcional)' => 'input'],
            $papel['fields']
        );

        $body = $this->get('/admin_usuario.php?id=' . $nobodyId, $admin)['body'];
        $this->assertStringNotContainsString('<style', $body, 'no style block of its own');
        foreach (['muted-note', 'class="erro"', 'class="ok"'] as $invented) {
            $this->assertStringNotContainsString($invented, $body);
        }
    }

    /**
     * A refusal shows as the first row of the form's table, spanning both
     * cells, as evento.php shows "Evento não salvo: ..." (BAJAAPP-92). Here:
     * an end date that is not a date.
     */
    public function testARefusedGrantIsShownAsARowOfTheForm(): void
    {
        $this->grant(self::CONTROLLER, 'admin');
        $admin = $this->loginAndGetCookies(self::CONTROLLER);
        $nobodyId = (int) $this->fetchValue('SELECT user_id FROM user WHERE username = ?', [self::NOBODY]);
        $page = '/admin_usuario.php?id=' . $nobodyId;
        $token = $this->tokenIn($this->get($page, $admin)['body']);

        $refused = $this->post("$page&act=grant", $admin, ['role' => 'judge', 'node' => 'prova:00NE/ZZN', 'valid_until' => 'not-a-date', '_csrf' => $token]);

        $layout = $this->layoutOf($refused['body'], 'act=grant');
        $this->assertSame(['Papel não dado: Data de término inválida.'], $layout['messages']);
        $this->assertSame([], $layout['otherRows']);
    }

    // ------------------------------------------------------------------

    /**
     * Describes the table of the form whose action contains $action:
     *
     *   tablesorter      the table has the class
     *   header           it has a <thead> with a cell in it
     *   submitInFooter   its <tfoot> holds an <input type="submit">
     *   fields           label => the control's tag, for each body row of
     *                    exactly two cells: text on the left, a control
     *                    (input, select, textarea) on the right
     *   messages         the text of each body row of ONE cell spanning two
     *   otherRows        any body row that is neither (should be none)
     *
     * @return array{tablesorter: bool, header: bool, submitInFooter: bool, fields: array<string, string>, messages: string[], otherRows: string[]}
     */
    private function layoutOf(string $html, string $action): array
    {
        $doc = new \DOMDocument();
        // The pages are HTML5, which this parser does not know: it warns
        // about tags like <details>, and the warnings mean nothing here.
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($doc);

        $table = null;
        foreach ($xpath->query('//form') as $form) {
            if (str_contains((string) $form->getAttribute('action'), $action)) {
                $table = $xpath->query('.//table', $form)->item(0);
                break;
            }
        }
        $this->assertNotNull($table, "no form posting to '$action' with a table in it");

        $layout = [
            'tablesorter' => in_array('tablesorter', explode(' ', (string) $table->getAttribute('class')), true),
            'header' => $xpath->query('./thead//th', $table)->length > 0,
            'submitInFooter' => $xpath->query('./tfoot//input[@type="submit"]', $table)->length > 0,
            'fields' => [],
            'messages' => [],
            'otherRows' => [],
        ];
        $normal = fn(string $text) => trim((string) preg_replace('/\s+/u', ' ', $text));
        foreach ($xpath->query('./tbody/tr', $table) as $row) {
            $cells = $xpath->query('./td', $row);
            if ($cells->length === 1 && $cells->item(0)->getAttribute('colspan') === '2') {
                $layout['messages'][] = $normal($cells->item(0)->textContent);
                continue;
            }
            $control = $cells->length === 2 ? $xpath->query('.//input | .//select | .//textarea', $cells->item(1))->item(0) : null;
            $labelHasControl = $cells->length === 2 && $xpath->query('.//input | .//select | .//textarea', $cells->item(0))->length > 0;
            if ($control === null || $labelHasControl) {
                $layout['otherRows'][] = $normal($row->textContent);
                continue;
            }
            $layout['fields'][$normal($cells->item(0)->textContent)] = $control->nodeName;
        }
        return $layout;
    }

    /** @param array<string, string> $fields */
    private function post(string $path, array $cookies, array $fields): array
    {
        return $this->request('POST', $path, $cookies, http_build_query($fields), self::JUIZ_SITE, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** The secret token a page printed into its forms (Baja\Auth\Csrf). */
    private function tokenIn(string $html): string
    {
        $this->assertSame(1, preg_match('/name="_csrf" value="([0-9a-f]+)"/', $html, $found), 'the page has no form token');
        return $found[1];
    }
}
