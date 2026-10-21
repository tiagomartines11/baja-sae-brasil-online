<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Engine\DigitSimilarity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How much one typed car number looks like another.
 *
 * When a counter types a number alone and two other cars crossed at that
 * same moment, the engine suggests the car whose number is the more likely
 * slip of the finger. These are the slips it knows, from most to least
 * alike:
 *
 *   0  two neighbouring digits swapped       41 typed as 14
 *   1  one digit wrong                       41 typed as 47
 *   2  one digit missing or one too many     41 typed as 4, or as 411
 *   3  nothing obvious in common
 */
final class DigitSimilarityTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int, 2: int}> typed, intended, expected rank */
    public static function pairs(): array
    {
        return [
            '41 typed as 14: swapped' => [14, 41, DigitSimilarity::TROCADOS],
            '132 typed as 123: the last two swapped' => [123, 132, DigitSimilarity::TROCADOS],
            '213 typed as 123: the first two swapped' => [123, 213, DigitSimilarity::TROCADOS],
            '321 typed as 123: first and last are not neighbours' => [123, 321, DigitSimilarity::NENHUMA],
            '41 typed as 47: one digit wrong' => [47, 41, DigitSimilarity::UM_DIGITO_DIFERENTE],
            '41 typed as 91: the other digit wrong' => [91, 41, DigitSimilarity::UM_DIGITO_DIFERENTE],
            '41 typed as 78: both digits wrong' => [78, 41, DigitSimilarity::NENHUMA],
            '41 typed as 4: last digit missing' => [4, 41, DigitSimilarity::DIGITO_FALTANDO],
            '41 typed as 1: first digit missing' => [1, 41, DigitSimilarity::DIGITO_FALTANDO],
            '41 typed as 411: one digit too many' => [411, 41, DigitSimilarity::DIGITO_FALTANDO],
            '141 typed as 11: middle digit missing' => [11, 141, DigitSimilarity::DIGITO_FALTANDO],
            '41 typed as 7: shorter, but not 41 with a digit removed' => [7, 41, DigitSimilarity::NENHUMA],
            '4 typed as 123: two digits too many' => [123, 4, DigitSimilarity::NENHUMA],
            '5 typed as 8: one digit wrong' => [8, 5, DigitSimilarity::UM_DIGITO_DIFERENTE],
        ];
    }

    #[DataProvider('pairs')]
    public function testEachKindOfSlipIsRecognised(int $typed, int $intended, int $expectedRank): void
    {
        $this->assertSame($expectedRank, DigitSimilarity::rank($typed, $intended));
        $this->assertSame($expectedRank, DigitSimilarity::rank($intended, $typed), 'The resemblance is the same both ways.');
    }

    /**
     * EXPECTED: 11 and 11 with "the digits swapped" is the same number, so
     * swapping equal digits is not a slip; and the order of the ranks is
     * what makes "smaller is more alike" true.
     */
    public function testTheRanksAreOrderedFromMostToLeastAlike(): void
    {
        $this->assertLessThan(DigitSimilarity::UM_DIGITO_DIFERENTE, DigitSimilarity::TROCADOS);
        $this->assertLessThan(DigitSimilarity::DIGITO_FALTANDO, DigitSimilarity::UM_DIGITO_DIFERENTE);
        $this->assertLessThan(DigitSimilarity::NENHUMA, DigitSimilarity::DIGITO_FALTANDO);
        $this->assertSame(DigitSimilarity::UM_DIGITO_DIFERENTE, DigitSimilarity::rank(112, 111), 'Not "swapped": swapping the two 1s changes nothing.');
        $this->assertSame(DigitSimilarity::NENHUMA, DigitSimilarity::rank(11, 11), 'A number is not a slip of itself.');
    }

    /**
     * EXPECTED: each rank has a few words in Portuguese for the reviewer,
     * and "nothing in common" has none.
     */
    public function testEachRankIsDescribedInPortuguese(): void
    {
        $this->assertSame('dígitos trocados', DigitSimilarity::describe(DigitSimilarity::TROCADOS));
        $this->assertSame('um dígito diferente', DigitSimilarity::describe(DigitSimilarity::UM_DIGITO_DIFERENTE));
        $this->assertSame('um dígito a mais ou a menos', DigitSimilarity::describe(DigitSimilarity::DIGITO_FALTANDO));
        $this->assertSame('', DigitSimilarity::describe(DigitSimilarity::NENHUMA));
    }
}
