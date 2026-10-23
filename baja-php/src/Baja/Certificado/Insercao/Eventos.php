<?php

namespace Baja\Certificado\Insercao;

use Baja\Certificado\Nome;
use Baja\Model\EventoQuery;

/**
 * Turning whatever the evento column says into an event code.
 *
 * A sheet exported from this system carries codes. A sheet somebody built by
 * hand carries one of the two names the event actually goes by, because a
 * code is what the event is called nowhere except in this database.
 *
 * There are two names because the table holds two. `nome` is the formal one
 * that a certificate prints — "22ª Competição Baja SAE BRASIL - Etapa Sul" —
 * and `titulo` is the short one with the year in it, "Baja SAE BRASIL - Etapa
 * Sul 2025". Which one a sheet carries depends on where it was built, and
 * neither is more correct than the other. Both resolve; nothing is guessed at.
 */
final class Eventos
{
    /** @var array<string, string>|null code => name, loaded once */
    private ?array $porCodigo = null;

    /** @var array<string, array<int, string>>|null key => codes answering to it */
    private ?array $porChave = null;

    /**
     * The code for a submitted value, or null if it is not exactly one event.
     *
     * Code first, then either name. All three are matched on the shared
     * comparison key, so case, accents and punctuation do not matter — and
     * nothing looser than that. "Etapa Sul" and "Etapa Sudeste" share a
     * prefix, and two events a season apart are worth exactly as much care as
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

        $candidatos = $this->porChave()[$chave] ?? [];

        // Exactly one, or nothing. Both name columns are free text and nothing
        // stops two events sharing a value, or one event's titulo reading like
        // another's nome; picking one would file a batch of certificates under
        // whichever happened to sort first.
        return count($candidatos) === 1 ? $candidatos[0] : null;
    }

    /**
     * Codes answering to this value, when more than one does.
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

        $candidatos = $this->porChave()[$chave] ?? [];

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
            $this->porCodigo[(string) $evento->getEventoId()] = self::decodificar(
                (string) $evento->getNome()
            );
        }

        return $this->porCodigo;
    }

    /**
     * Stored names carry HTML entities as literal text — "Baja SAE&nbsp;BRASIL"
     * holds those six characters. Decoding is what lets a pasted name, which
     * has a real space or a real non-breaking space in it, reach the row.
     */
    private static function decodificar(string $valor): string
    {
        return html_entity_decode($valor, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Every written form an event answers to, mapped to the events answering.
     *
     * A code can appear under more than one key — its own nome and its own
     * titulo — but only once per key, so an event whose two names happen to
     * fold alike does not look like two candidates and trip the ambiguity
     * check against itself.
     *
     * @return array<string, array<int, string>>
     */
    private function porChave(): array
    {
        if ($this->porChave !== null) {
            return $this->porChave;
        }

        $this->porChave = [];

        foreach (EventoQuery::create()->orderByEventoId('desc')->find() as $evento) {
            $codigo = (string) $evento->getEventoId();

            foreach ([$evento->getNome(), $evento->getTitulo()] as $escrito) {
                $chave = Nome::chave(self::decodificar((string) $escrito));

                if ($chave === '' || in_array($codigo, $this->porChave[$chave] ?? [], true)) {
                    continue;
                }

                $this->porChave[$chave][] = $codigo;
            }
        }

        return $this->porChave;
    }
}
