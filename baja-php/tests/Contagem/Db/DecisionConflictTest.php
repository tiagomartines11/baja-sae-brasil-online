<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Db;

use Baja\Contagem\Review\DecisionValidator;
use Baja\Contagem\Review\StateSnapshot;
use Baja\Contagem\Service\Clock;
use Baja\Model\ContagemDecisaoQuery;

/**
 * Two reviewers work on the same race at the same time. What happens when
 * both act on the same thing?
 *
 * How the review screen works, in short:
 *   1. Each reviewer's browser asks the server for the "state" of the race:
 *      the list of flags (things that look wrong), the car table and so on.
 *   2. Every flag in that state comes with an "assinatura" (signature): a
 *      fingerprint of the entries the flag is about, as they were at that
 *      moment.
 *   3. When a reviewer clicks a button, the browser sends the decision
 *      together with the signature it was shown.
 *   4. The server computes the signature again. If it is no longer the same,
 *      something changed after the reviewer's screen was drawn, and the
 *      decision is refused with HTTP status 409 ("conflict") plus the fresh
 *      state, so the reviewer can look again.
 *
 * These tests play both reviewers by calling the server-side code directly,
 * exactly as contagem_decidir.php does, against the test database.
 *
 * Needs the dev database: run with BAJA_TEST_DB=1 (see DbTestCase).
 */
final class DecisionConflictTest extends DbTestCase
{
    // In every test below:
    //   - $this->at(60) means "60 seconds after the start of the race";
    //   - self::CAR_A is one of the three cars of the made-up test event;
    //   - Clock::freezeAt(...) sets what the server believes "now" is, so
    //     the test does not depend on when it runs. DbTestCase unfreezes it
    //     again after each test.

    /**
     * SITUATION: one minute into the race, only counter 1 types a car
     * number. Counters 2 and 3 were at their posts (their devices have
     * uploaded everything up to minute 2) and typed nothing. Maybe counter 1
     * invented a pass, maybe the other two missed a real one: the system
     * cannot know, so it raises a flag for a person to decide.
     *
     * Ana and Beto both have that flag open. Ana decides first: she voids
     * the entry. A moment later Beto, whose screen still shows the flag as
     * it was, clicks "manter" (keep it).
     *
     * EXPECTED: Ana's decision is stored. Beto's is refused with 409, he is
     * told that Ana already decided, he receives the fresh state, and
     * nothing of his is stored.
     */
    public function testSecondReviewerOnTheSameFlagIsRefusedAndToldWhoDecided(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], $car, $this->at(60));
        $this->syncAll($this->at(120));
        Clock::freezeAt($this->at(180));

        // Both reviewers load the screen. They see the same flag, with the same signature.
        $anaScreen = StateSnapshot::build($this->service, 'ana');
        $betoScreen = StateSnapshot::build($this->service, 'beto');
        $flagOnAnaScreen = $this->openFlagAbout($anaScreen, $entryId);
        $flagOnBetoScreen = $this->openFlagAbout($betoScreen, $entryId);
        $this->assertSame($flagOnAnaScreen['assinatura'], $flagOnBetoScreen['assinatura']);

        // Ana clicks first: void the entry.
        $anaResponse = DecisionValidator::decide(
            $this->service,
            $this->requestFromScreen($anaScreen, $flagOnAnaScreen, ['tipo' => 'anular', 'passagem_id' => $entryId]),
            'ana'
        );
        $this->assertSame(200, $anaResponse['status'], 'the first reviewer\'s decision must be accepted');

        // Beto clicks second, still sending what HIS screen showed: the old signature.
        $betoResponse = DecisionValidator::decide(
            $this->service,
            $this->requestFromScreen($betoScreen, $flagOnBetoScreen, ['tipo' => 'confirmar', 'passagem_id' => $entryId]),
            'beto'
        );

        // Refused, and told why.
        $this->assertSame(409, $betoResponse['status'], 'the second reviewer must get a conflict');
        $this->assertSame('conflito', $betoResponse['body']['erro']);
        $this->assertSame(DecisionValidator::MOTIVO_JA_DECIDIDO, $betoResponse['body']['motivo']);
        $this->assertSame('ana', $betoResponse['body']['por']);
        $this->assertStringContainsString('Já decidido por ana', $betoResponse['body']['mensagem']);

        // The refusal carries the fresh state: not older than Beto's screen,
        // with Ana's decision at the top of the history and the flag gone.
        // (Not strictly "greater": without Redis the version is the clock in
        // whole seconds, and this test runs in less than one.)
        $freshState = $betoResponse['body']['estado'];
        $this->assertGreaterThanOrEqual($betoScreen['versao'], $freshState['versao']);
        $this->assertSame('ana', $freshState['historico'][0]['usuario']);
        $this->assertSame('anular', $freshState['historico'][0]['tipo']);
        $this->assertNull(
            $this->findOpenFlagAbout($freshState, $entryId),
            'once the entry is voided there is nothing left to flag'
        );

        // Only Ana's decision exists. Beto's was not stored "just in case".
        $this->assertSame(1, $this->countDecisions());
    }

    /**
     * SITUATION: the same lonely entry from counter 1. Ana has its flag
     * open. Counter 2's phone had been without signal; while Ana is still
     * thinking, it reconnects and uploads an entry for the same car one
     * second later. Now two counters agree and the flag's story is a
     * different one. Nobody made a decision.
     *
     * Ana, who has not seen that yet, clicks "anular".
     *
     * EXPECTED: refused with 409, but this time the reason is "the data
     * changed" and no name is given, because no person decided anything.
     * Ana's void is not stored: voiding an entry that a second counter has
     * just confirmed is precisely the mistake this check exists to prevent.
     */
    public function testDecisionIsRefusedWhenALateUploadChangedTheEntries(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], $car, $this->at(60));
        $this->syncAll($this->at(120));
        Clock::freezeAt($this->at(180));

        $anaScreen = StateSnapshot::build($this->service, 'ana');
        $flag = $this->openFlagAbout($anaScreen, $entryId);

        // Counter 2's late upload arrives: same car, one second after counter 1's entry.
        $this->addEntry($this->fontes[1], $car, $this->at(61));

        $response = DecisionValidator::decide(
            $this->service,
            $this->requestFromScreen($anaScreen, $flag, ['tipo' => 'anular', 'passagem_id' => $entryId]),
            'ana'
        );

        $this->assertSame(409, $response['status']);
        $this->assertSame(DecisionValidator::MOTIVO_DADOS_MUDARAM, $response['body']['motivo']);
        $this->assertNull($response['body']['por']);
        $this->assertStringContainsString('Os dados mudaram', $response['body']['mensagem']);
        $this->assertSame(0, $this->countDecisions());
    }

    /**
     * SITUATION: Beto was refused (first test). He looks at the fresh state
     * he was sent and decides again from it: he undoes Ana's void.
     *
     * EXPECTED: accepted. A refusal is not a lock: it only asks the
     * reviewer to look at the current picture before deciding.
     */
    public function testAfterARefusalADecisionMadeFromTheFreshStateIsAccepted(): void
    {
        $car = self::CAR_A;
        $this->startRace(self::RACE_START_MS);
        $entryId = $this->addEntry($this->fontes[0], $car, $this->at(60));
        $this->syncAll($this->at(120));
        Clock::freezeAt($this->at(180));

        $betoScreen = StateSnapshot::build($this->service, 'beto');
        $flag = $this->openFlagAbout($betoScreen, $entryId);
        $anaResponse = DecisionValidator::decide(
            $this->service,
            $this->requestFromScreen($betoScreen, $flag, ['tipo' => 'anular', 'passagem_id' => $entryId]),
            'ana'
        );
        $refusal = DecisionValidator::decide(
            $this->service,
            $this->requestFromScreen($betoScreen, $flag, ['tipo' => 'confirmar', 'passagem_id' => $entryId]),
            'beto'
        );
        $this->assertSame(409, $refusal['status']);

        // Beto now uses the state that came with the refusal.
        $freshState = $refusal['body']['estado'];
        $undo = DecisionValidator::decide(
            $this->service,
            [
                'decisao' => ['tipo' => 'desfazer', 'desfaz_decisao_id' => $anaResponse['body']['decisao_id']],
                'versao' => $freshState['versao'],
                'passagem_ids' => [],
                'assinatura' => null,
                'ultima_decisao_id' => $freshState['ultima_decisao_id'],
            ],
            'beto'
        );

        $this->assertSame(200, $undo['status']);
        // Ana's void is still in the history, marked as no longer in force.
        $history = $undo['body']['estado']['historico'];
        $this->assertSame('desfazer', $history[0]['tipo']);
        $this->assertSame('beto', $history[0]['usuario']);
        $this->assertFalse($history[1]['ativa']);
    }

    /**
     * The request a browser sends: the decision, plus what the screen was
     * showing when the reviewer clicked.
     *
     * @param array<string, mixed> $screen the state the reviewer's browser has
     * @param array<string, mixed> $flag the flag the reviewer has open
     * @param array<string, mixed> $decision
     * @return array<string, mixed>
     */
    private function requestFromScreen(array $screen, array $flag, array $decision): array
    {
        return [
            'decisao' => $decision,
            'versao' => $screen['versao'],
            'passagem_ids' => $flag['passagem_ids'],
            'assinatura' => $flag['assinatura'],
            'ultima_decisao_id' => $screen['ultima_decisao_id'],
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null the open flag that involves this entry, if any
     */
    private function findOpenFlagAbout(array $state, int $entryId): ?array
    {
        foreach ($state['alertas_abertos'] as $flag) {
            if (in_array($entryId, $flag['passagem_ids'], true)) {
                return $flag;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function openFlagAbout(array $state, int $entryId): array
    {
        $flag = $this->findOpenFlagAbout($state, $entryId);
        $this->assertNotNull($flag, 'an entry recorded by a single counter must raise a flag for the reviewers');
        return $flag;
    }

    private function countDecisions(): int
    {
        return ContagemDecisaoQuery::create()
            ->filterByEventoId($this->prova->getEventoId())
            ->filterByProvaId($this->prova->getProvaId())
            ->count();
    }
}
