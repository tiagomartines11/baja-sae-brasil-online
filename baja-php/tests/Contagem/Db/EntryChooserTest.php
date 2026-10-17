<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Access\ContagemAccess;
use Baja\Model\ContagemFonte;

/**
 * Where each kind of user lands when they click the prova on the juiz
 * start page.
 *
 * BACKGROUND FOR SOMEONE NEW TO THIS
 *
 * The prova's line on the start page leads to contagem.php. The controller
 * (00NE_ZZL) gets the hub there. Everybody else gets a chooser: one big
 * link per source a controller linked them to (they hold 00NE_ZZL_CONT),
 * and "Registro de box" if they hold the pit code (00NE_ZZL_BOX). The
 * chooser is shown even when it has a single link.
 *
 * WHAT WENT WRONG AT THE 26SE EVENT
 *
 * A pit volunteer holding only the pit code logged in and found nothing:
 * the start page listed the prova only for controllers and counters, and
 * contagem.php refused anybody who was not a counter. The pit page itself
 * would have opened for them, but nothing linked there except the
 * controller's hub. A counter who also held the pit code was never offered
 * the pit page either.
 */
final class EntryChooserTest extends DbTestCase
{
    private const PIT_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_BOX';
    private const COUNTER_CODE = self::EVENTO_ID . '_' . self::PROVA_ID . '_CONT';
    private const CONTROLLER_CODE = self::EVENTO_ID . '_' . self::PROVA_ID;

    private const PIT_PAGE = 'contagem_box.php?p=' . self::PROVA_ID;

    private function countPage(ContagemFonte $fonte): string
    {
        return 'contagem_contar.php?p=' . self::PROVA_ID . '&f=' . (int) $fonte->getFonteId();
    }

    /** A controller links a user to a source on the "Usuários" page. */
    private function linkUser(ContagemFonte $fonte, string $username): void
    {
        $this->executeSql(
            'INSERT INTO contagem_fonte_usuario (fonte_id, usuario, adicionado_por, criado_em_ms) VALUES (?, ?, ?, ?)',
            [$fonte->getFonteId(), $username, 'zzt_controle', $this->at(0)]
        );
    }

    /**
     * Logs the user in and returns what the chooser offers them, as
     * label => address, or null when the prova is not even listed for them.
     *
     * @return array<string, string>|null
     */
    private function chooserOf(string $username, array $permissions): ?array
    {
        $this->createUser($username, $permissions);
        $this->loginAs($username);
        $this->newRequest();
        if (!ContagemAccess::mayEnter($this->prova)) {
            return null;
        }
        $offered = [];
        foreach (ContagemAccess::entryDestinations($this->prova, $username) as $destination) {
            $offered[$destination['rotulo']] = $destination['endereco'];
        }
        return $offered;
    }

    /**
     * SITUATION: a pit volunteer holds only "index" and the pit code.
     *
     * WHAT WENT WRONG BEFORE: the prova was not on their start page, and
     * contagem.php refused them.
     *
     * EXPECTED: they may enter, and the chooser offers the pit log and
     * nothing else (no count page).
     */
    public function testAPitVolunteerIsOfferedThePitLogAndNothingElse(): void
    {
        $offered = $this->chooserOf(self::USER_PREFIX . 'so_box', ['index', self::PIT_CODE]);

        $this->assertSame(['Registro de box' => self::PIT_PAGE], $offered);
    }

    /**
     * SITUATION: the same, in a prova that also logs the fueling station.
     *
     * EXPECTED: the link says so, as on the controller's hub.
     */
    public function testThePitLinkNamesTheFuelingStationWhenItIsLogged(): void
    {
        $this->changeSettings(['abastecimento' => true]);

        $offered = $this->chooserOf(self::USER_PREFIX . 'so_box', ['index', self::PIT_CODE]);

        $this->assertSame(['Registro de box e abastecimento' => self::PIT_PAGE], $offered);
    }

    /**
     * SITUATION: a counter linked to Contagem 1 only.
     *
     * WHAT CHANGED: they used to be redirected straight to the count page,
     * which also turned the count page's "Voltar" into a loop back to it.
     *
     * EXPECTED: a chooser with the one source.
     */
    public function testACounterWithOneSourceGetsAChooserWithThatSource(): void
    {
        $username = self::USER_PREFIX . 'contador';
        $this->linkUser($this->fontes[0], $username);

        $offered = $this->chooserOf($username, ['index', self::COUNTER_CODE]);

        $this->assertSame(['Contagem 1' => $this->countPage($this->fontes[0])], $offered);
    }

    /**
     * SITUATION: a counter linked to Contagem 1 who also holds the pit code.
     *
     * WHAT WENT WRONG BEFORE: they went straight to the count page and were
     * never offered the pit page.
     *
     * EXPECTED: both, the source first.
     */
    public function testACounterWhoAlsoLogsThePitIsOfferedBoth(): void
    {
        $username = self::USER_PREFIX . 'contador_box';
        $this->linkUser($this->fontes[0], $username);

        $offered = $this->chooserOf($username, ['index', self::COUNTER_CODE, self::PIT_CODE]);

        $this->assertSame([
            'Contagem 1' => $this->countPage($this->fontes[0]),
            'Registro de box' => self::PIT_PAGE,
        ], $offered);
    }

    /**
     * SITUATION: a relief linked to Contagem 3 and Contagem 1, who also
     * holds the pit code.
     *
     * EXPECTED: the two sources in display order, then the pit log.
     */
    public function testTwoSourcesAndThePitLogAreOfferedInOrder(): void
    {
        $username = self::USER_PREFIX . 'reserva_box';
        $this->linkUser($this->fontes[2], $username);
        $this->linkUser($this->fontes[0], $username);

        $offered = $this->chooserOf($username, ['index', self::COUNTER_CODE, self::PIT_CODE]);

        $this->assertSame([
            'Contagem 1' => $this->countPage($this->fontes[0]),
            'Contagem 3' => $this->countPage($this->fontes[2]),
            'Registro de box' => self::PIT_PAGE,
        ], $offered);
    }

    /**
     * SITUATION: somebody kept the counter code after their links were
     * removed by hand, and holds the pit code.
     *
     * EXPECTED: the pit log only. The stale code opens no source.
     */
    public function testAStaleCounterCodeWithThePitCodeOffersThePitLogOnly(): void
    {
        $offered = $this->chooserOf(self::USER_PREFIX . 'velho_box', ['index', self::COUNTER_CODE, self::PIT_CODE]);

        $this->assertSame(['Registro de box' => self::PIT_PAGE], $offered);
    }

    /**
     * SITUATION: a stale counter code and nothing else.
     *
     * EXPECTED: the prova is listed and contagem.php opens, with nothing to
     * choose: the page then says "Você não está vinculado a nenhuma
     * contagem", which tells them whom to ask.
     */
    public function testAStaleCounterCodeAloneEntersAndFindsNothing(): void
    {
        $offered = $this->chooserOf(self::USER_PREFIX . 'velho', ['index', self::COUNTER_CODE]);

        $this->assertSame([], $offered);
    }

    /**
     * SITUATION: somebody logged in with no code of this prova, and somebody
     * linked to a source in the database but without the counter code (the
     * code is what the start page and contagem.php look at).
     *
     * EXPECTED: neither may enter.
     */
    public function testNoCodeOfTheProvaMeansNoEntry(): void
    {
        $this->assertNull($this->chooserOf(self::USER_PREFIX . 'so_index', ['index']));

        $linkedWithoutCode = self::USER_PREFIX . 'sem_codigo';
        $this->linkUser($this->fontes[0], $linkedWithoutCode);
        $this->assertNull($this->chooserOf($linkedWithoutCode, ['index']));
    }

    /**
     * SITUATION: the controller, who holds neither the counter nor the pit
     * code.
     *
     * EXPECTED: may enter (contagem.php then shows the hub).
     */
    public function testTheControllerMayEnter(): void
    {
        $this->assertNotNull($this->chooserOf(self::USER_PREFIX . 'chefe', ['index', self::CONTROLLER_CODE]));
    }

    // ------------------------------------------------------------------
    // The pages themselves
    // ------------------------------------------------------------------
    //
    // The tests above ask ContagemAccess what the pages would show. These
    // run index.php and contagem.php for real (in a separate PHP process,
    // see DbTestCase::renderJuizPage), so a page that stopped asking
    // ContagemAccess, or printed the list wrong, is caught as well.

    /**
     * SITUATION: the pit volunteer of the first test opens the start page
     * and clicks the prova.
     *
     * EXPECTED: the start page lists the prova, and contagem.php shows the
     * chooser with the pit log as its only link.
     */
    public function testThePagesLeadAPitVolunteerToThePitLog(): void
    {
        $username = self::USER_PREFIX . 'so_box';
        $this->createUser($username, ['index', self::PIT_CODE]);

        $start = $this->renderJuizPage('index.php', $username);
        $this->assertStringContainsString('href="contagem.php?p=' . self::PROVA_ID . '"', $start);

        $chooser = $this->renderJuizPage('contagem.php', $username, ['p' => self::PROVA_ID]);
        $this->assertStringContainsString('Escolha onde você vai trabalhar', $chooser);
        $this->assertStringContainsString('<a href="' . self::PIT_PAGE . '">Registro de box</a>', $chooser);
        $this->assertStringNotContainsString('contagem_contar.php', $chooser);
    }

    /**
     * SITUATION: a counter linked to Contagem 1 only clicks the prova.
     *
     * WHAT CHANGED: contagem.php used to send them on to the count page
     * (a redirect prints nothing).
     *
     * EXPECTED: the chooser, with Contagem 1 as its one link.
     */
    public function testACounterWithOneSourceSeesTheChooserInsteadOfARedirect(): void
    {
        $username = self::USER_PREFIX . 'contador';
        $this->createUser($username, ['index', self::COUNTER_CODE]);
        $this->linkUser($this->fontes[0], $username);

        $chooser = $this->renderJuizPage('contagem.php', $username, ['p' => self::PROVA_ID]);

        $this->assertStringContainsString('Escolha onde você vai trabalhar', $chooser);
        $this->assertStringContainsString(
            '<a href="' . htmlspecialchars($this->countPage($this->fontes[0])) . '">Contagem 1</a>',
            $chooser
        );
        $this->assertStringNotContainsString('contagem_box.php', $chooser);
    }

    /**
     * SITUATION: a stale counter code and nothing else.
     *
     * EXPECTED: contagem.php opens and says whom to ask.
     */
    public function testTheStaleCounterCodeAloneIsToldWhomToAsk(): void
    {
        $username = self::USER_PREFIX . 'velho';
        $this->createUser($username, ['index', self::COUNTER_CODE]);

        $page = $this->renderJuizPage('contagem.php', $username, ['p' => self::PROVA_ID]);

        $this->assertStringContainsString('Você não está vinculado a nenhuma contagem', $page);
    }

    /**
     * SITUATION: somebody with no code of this prova.
     *
     * EXPECTED: the start page does not list it, and contagem.php refuses.
     */
    public function testThePagesRefuseSomebodyWithNoCodeOfTheProva(): void
    {
        $username = self::USER_PREFIX . 'so_index';
        $this->createUser($username, ['index']);

        $start = $this->renderJuizPage('index.php', $username);
        $this->assertStringNotContainsString('contagem.php?p=' . self::PROVA_ID, $start);

        $page = $this->renderJuizPage('contagem.php', $username, ['p' => self::PROVA_ID]);
        $this->assertSame('Você não tem acesso a essa página', trim($page));
    }

    /**
     * SITUATION: the controller opens the hub of a prova that also logs the
     * fueling station.
     *
     * EXPECTED: the bar at the top and the hub's list name the pit page the
     * same way, "Registro de box e abastecimento".
     */
    public function testTheControllerBarNamesThePitLogLikeTheHub(): void
    {
        $this->changeSettings(['abastecimento' => true]);
        $username = self::USER_PREFIX . 'chefe';
        $this->createUser($username, ['index', self::CONTROLLER_CODE]);

        $hub = $this->renderJuizPage('contagem.php', $username, ['p' => self::PROVA_ID]);

        $link = '<a href="' . self::PIT_PAGE . '">Registro de box e abastecimento</a>';
        $this->assertSame(2, substr_count($hub, $link), 'once in the bar, once in the hub');
        $this->assertStringNotContainsString('>Registro de box</a>', $hub);
    }
}
