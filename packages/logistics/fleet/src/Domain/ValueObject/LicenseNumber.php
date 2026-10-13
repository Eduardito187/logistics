<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Número de licencia de conducir del chofer. Único por chofer; su vigencia la lleva un DocumentExpiry.
 *
 * Se normaliza a mayúsculas y se valida que sea alfanumérico (admite guion). No modela la categoría de
 * la licencia: eso, si hace falta, se agrega como documento aparte.
 */
final readonly class LicenseNumber
{
    private const PATTERN = '/^[A-Z0-9-]{4,20}$/';

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw FleetException::because("Número de licencia inválido: {$value}.");
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
