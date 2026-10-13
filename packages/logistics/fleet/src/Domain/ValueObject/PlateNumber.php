<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Placa (matrícula) del vehículo. Identifica físicamente al camión y es única en la flota.
 *
 * Se normaliza a mayúsculas y se valida un formato amplio (letras, dígitos y guion) para tolerar los
 * distintos formatos de placa de Bolivia sin casarse con uno solo. La unicidad la garantiza la base.
 */
final readonly class PlateNumber
{
    private const PATTERN = '/^[A-Z0-9]{3,8}(-[A-Z0-9]{1,4})?$/';

    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw FleetException::because("Placa con formato inválido: {$value}.");
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
