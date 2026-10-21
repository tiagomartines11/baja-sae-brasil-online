<?php
declare(strict_types=1);

namespace Baja\Tests\Contagem\Engine;

use Baja\Contagem\Sync\UploadFields;
use PHPUnit\Framework\TestCase;

/**
 * The picture an automatic source links to an entry ("imagem") is an opaque
 * id appended to the source's image address. Only plain ids are kept.
 */
final class ImageReferenceTest extends TestCase
{
    /**
     * SITUATION: a system sends ids for the pictures behind its entries.
     *
     * EXPECTED: a plain id of up to 64 characters is kept. Anything that
     * could climb out of the address, carry a query, or end in a newline
     * (which `$` would let through) is dropped.
     */
    public function testOnlyPlainIdsAreKept(): void
    {
        $this->assertSame('cam1-000123.jpg', UploadFields::imageRefOf(['imagem' => 'cam1-000123.jpg']));
        $this->assertSame(str_repeat('a', 64), UploadFields::imageRefOf(['imagem' => str_repeat('a', 64)]));

        foreach (["abc\n", '../segredo', '.oculto', 'a?b=1', 'a/b', '', str_repeat('a', 65)] as $bad) {
            $this->assertNull(UploadFields::imageRefOf(['imagem' => $bad]), json_encode($bad));
        }
        $this->assertNull(UploadFields::imageRefOf(['imagem' => 123]));
        $this->assertNull(UploadFields::imageRefOf([]));
    }
}
