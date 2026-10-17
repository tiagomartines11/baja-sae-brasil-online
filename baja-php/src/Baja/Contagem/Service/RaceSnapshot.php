<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * The result of one whole-race recalculation, kept so that screens and the
 * public feed can be served without recalculating.
 *
 * Nothing in it depends on the moment it is read. What does change with the
 * clock (the race clock, whether a car is late) is worked out from it when
 * it is served; see LiveStatus.
 */
final class RaceSnapshot
{
    /**
     * Identifies this snapshot. It is an opaque token: two tokens are either
     * equal ("you already have this") or not. It carries no order, so a
     * Redis restart can never make a newer snapshot look older.
     */
    public string $token;

    /** When the recalculation that produced it started. */
    public int $geradoEmMs;

    /** The change count it was computed from (see ChangeCounter); null when unknown. */
    public ?int $mudancas;

    /**
     * What SnapshotBuilder produced: 'estado' (review screen), 'feed'
     * (public feed), 'carros' (per-car numbers for LiveStatus).
     *
     * @var array<string, mixed>
     */
    public array $dados;

    /**
     * @param array<string, mixed> $dados
     */
    public function __construct(string $token, int $geradoEmMs, ?int $mudancas, array $dados)
    {
        $this->token = $token;
        $this->geradoEmMs = $geradoEmMs;
        $this->mudancas = $mudancas;
        $this->dados = $dados;
    }

    public function toJson(): string
    {
        return (string) json_encode([
            'token' => $this->token,
            'gerado_em_ms' => $this->geradoEmMs,
            'mudancas' => $this->mudancas,
            'dados' => $this->dados,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['token'], $decoded['gerado_em_ms'], $decoded['dados']) || !is_array($decoded['dados'])) {
            return null;
        }
        return new self(
            (string) $decoded['token'],
            (int) $decoded['gerado_em_ms'],
            isset($decoded['mudancas']) ? (int) $decoded['mudancas'] : null,
            $decoded['dados']
        );
    }
}
