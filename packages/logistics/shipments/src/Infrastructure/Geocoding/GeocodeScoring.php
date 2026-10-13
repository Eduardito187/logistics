<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use Logistics\Shipments\Domain\ValueObject\GeocodeMatchType;

/**
 * Criterios de puntaje de confianza, configurables (SHP: "criterios/umbrales reales configurables").
 *
 * Traduce el tipo de coincidencia de dominio (match exacto vs aproximado) a un score 0..100, aplica la
 * penalización por coincidencia parcial y define el score de "sin resultado". Vive en Infrastructure
 * porque son knobs operativos (se calibran contra la realidad boliviana), no reglas de negocio puras.
 * Los adaptadores (Google, Nominatim) comparten estos criterios para puntuar de forma homogénea.
 */
final readonly class GeocodeScoring
{
    /** Scores base por defecto por tipo de coincidencia (sobreescribibles por configuración). */
    private const DEFAULT_MATCH_SCORES = [
        'EXACT' => 95,
        'INTERPOLATED' => 80,
        'APPROXIMATE' => 45,
        'NONE' => 10,
    ];

    private const DEFAULT_PARTIAL_MATCH_PENALTY = 25;

    private const DEFAULT_NO_RESULT_SCORE = 10;

    /**
     * @param array<string, int> $matchScores score base por GeocodeMatchType->value
     */
    private function __construct(
        private array $matchScores,
        private int $partialMatchPenalty,
        private int $noResultScore,
    ) {}

    /**
     * Construye los criterios desde la configuración del contexto, cayendo a los valores por defecto
     * cuando una clave no viene o no es del tipo esperado.
     *
     * @param array<array-key, mixed> $config bloque logistics-shipments.geocoder.scoring
     */
    public static function fromConfig(array $config): self
    {
        $matchScores = self::DEFAULT_MATCH_SCORES;
        $configured = $config['match_scores'] ?? null;
        if (is_array($configured)) {
            foreach (self::DEFAULT_MATCH_SCORES as $type => $default) {
                $value = $configured[strtolower($type)] ?? $configured[$type] ?? null;
                if (is_int($value) || (is_string($value) && is_numeric($value))) {
                    $matchScores[$type] = self::clamp((int) $value);
                }
            }
        }

        $penalty = $config['partial_match_penalty'] ?? null;
        $noResult = $config['no_result_score'] ?? null;

        return new self(
            $matchScores,
            is_numeric($penalty) ? max(0, (int) $penalty) : self::DEFAULT_PARTIAL_MATCH_PENALTY,
            is_numeric($noResult) ? self::clamp((int) $noResult) : self::DEFAULT_NO_RESULT_SCORE,
        );
    }

    /**
     * Score 0..100 para un tipo de coincidencia, restando la penalización si fue coincidencia parcial.
     */
    public function scoreFor(GeocodeMatchType $matchType, bool $partialMatch = false): int
    {
        $base = $this->matchScores[$matchType->value] ?? $this->matchScores[GeocodeMatchType::Approximate->value];

        if ($partialMatch) {
            $base -= $this->partialMatchPenalty;
        }

        return self::clamp($base);
    }

    /**
     * Score para una dirección que el proveedor no ubicó (ZERO_RESULTS / respuesta vacía).
     */
    public function noResultScore(): int
    {
        return $this->noResultScore;
    }

    private static function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
