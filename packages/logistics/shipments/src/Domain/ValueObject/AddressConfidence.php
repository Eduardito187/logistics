<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Confianza de una dirección: un score 0..100 y su nivel (High/Medium/Low) derivado por umbrales.
 *
 * El score lo produce el geocodificador (ver AddressConfidenceScorer). El nivel nunca se pasa a mano:
 * siempre se deriva del score, así no pueden quedar inconsistentes.
 */
final readonly class AddressConfidence
{
    private function __construct(
        public int $score,
        public AddressConfidenceLevel $level,
    ) {}

    public static function of(int $score): self
    {
        if ($score < 0 || $score > 100) {
            throw ShipmentsException::because("score de confianza fuera de rango (0..100): {$score}");
        }

        return new self($score, AddressConfidenceLevel::fromScore($score));
    }

    public function isLow(): bool
    {
        return $this->level === AddressConfidenceLevel::Low;
    }
}
