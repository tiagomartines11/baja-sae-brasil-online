<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Nome;
use Baja\Model\EventoQuery;

/**
 * Turning whatever the evento column says into an event code.
 *
 * A sheet exported from this system carries codes. A sheet somebody built by
 * hand carries names — "27ª Competição Baja SAE BRASIL" — because that is
 * what the event is called everywhere except in this database. Both have to
 * work, and neither may be guessed at.
 */
final class Eventos
{
    /** @var array<string, string>|null code => name, loaded once */
    private ?array $porCodigo = null;

    /** @var array<string, array<int, string>>|null key => codes sharing it */
    private ?array $porNome = null;

    /**
     * The code for a submitted value, or null if it is not exactly one event.
     *
     * Code first, then name. Both are matched on the shared comparison key,
     * so case, accents and punctuation do not matter — and nothing looser
     * than that. "Etapa Sul" and "Etapa Sudeste" share a prefix, and two
     * events an hour apart in the calendar are worth exactly as much care as
     * two roles that read alike.
     */
    public function resolve(string $raw): ?string
    {
        $chave = Nome::chave($raw);

        if ($chave === '') {
            return null;
        }

        foreach ($this->porCodigo() as $codigo => $_nome) {
            if (Nome::chave($codigo) === $chave) {
                return $codigo;
            }
        }

        $candidatos = $this->porNome()[$chave] ?? [];

        // Exactly one, or nothing. Event names are free text and nothing stops
        // two of them being identical; picking one would file a batch of
        // certificates under whichever event happened to sort first.
        return count($candidatos) === 1 ? $candidatos[0] : null;
    }

    /**
     * Codes whose name matches, when more than one does.
     *
     * Lets the caller say "that name belongs to two events, which one" rather
     * than "no such event", which would be both wrong and unactionable.
     *
     * @return array<int, string>
     */
    public function ambiguos(string $raw): array
    {
        $chave = Nome::chave($raw);

        if ($chave === '') {
            return [];
        }

        $candidatos = $this->porNome()[$chave] ?? [];

        return count($candidatos) > 1 ? $candidatos : [];
    }

    public function existe(string $codigo): bool
    {
        return isset($this->porCodigo()[$codigo]);
    }

    public function nome(string $codigo): string
    {
        return $this->porCodigo()[$codigo] ?? '';
    }

    /** @return array<string, string> */
    public function porCodigo(): array
    {
        if ($this->porCodigo !== null) {
            return $this->porCodigo;
        }

        $this->porCodigo = [];
        foreach (EventoQuery::create()->orderByEventoId('desc')->find() as $evento) {
            // Stored names carry HTML entities as literal text — "Baja
            // SAE&nbsp;BRASIL" holds those six characters. Decoding here is
            // what makes the pasted name, which has a real space or a real
            // non-breaking space in it, reach this row.
            $this->porCodigo[(string) $evento->getEventoId()] = html_entity_decode(
                (string) $evento->getNome(),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return $this->porCodigo;
    }

    /** @return array<string, array<int, string>> */
    private function porNome(): array
    {
        if ($this->porNome !== null) {
            return $this->porNome;
        }

        $this->porNome = [];
        foreach ($this->porCodigo() as $codigo => $nome) {
            $chave = Nome::chave($nome);
            if ($chave !== '') {
                $this->porNome[$chave][] = $codigo;
            }
        }

        return $this->porNome;
    }
}
