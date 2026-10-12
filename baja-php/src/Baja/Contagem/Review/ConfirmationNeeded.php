<?php
declare(strict_types=1);

namespace Baja\Contagem\Review;

use InvalidArgumentException;

/**
 * An action that was NOT carried out because it needs the person to say
 * "yes, I know" first. The message says what would happen; the page shows it
 * with a button that sends the same form again plus the confirmation field.
 *
 * It extends InvalidArgumentException on purpose: code that only knows
 * "refused, show the sentence" keeps working, and nothing is ever stored by
 * an action that threw this.
 */
final class ConfirmationNeeded extends InvalidArgumentException
{
    /** Name of the form field that carries the "yes". */
    public string $campo;

    /** Label of the button that confirms. */
    public string $rotulo;

    /**
     * More fields the confirming form must send, name => value. Used to
     * carry what must not change between the question and the answer: the
     * instant of the click on "Largar agora", which IS the start time.
     *
     * @var array<string, string>
     */
    public array $campos = [];

    public function __construct(string $message, string $campo, string $rotulo)
    {
        parent::__construct($message);
        $this->campo = $campo;
        $this->rotulo = $rotulo;
    }
}
