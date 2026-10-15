<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Número de teléfono de una persona de la flota. No valida prefijo de país ni operadora: acepta dígitos
 * y los separadores habituales (`+`, `-`, espacio), con una longitud razonable, para no rechazar
 * formatos locales e internacionales legítimos.
 */
final readonly class PhoneNumber
{
    private const PATTERN = '/^[0-9+\-\s]{6,20}$/';

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        // Colapsa espacios internos y recorta los extremos antes de validar.
        $normalized = trim((string) preg_replace('/\s+/', ' ', $value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw FleetException::because("Número de teléfono inválido: {$value}.");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
