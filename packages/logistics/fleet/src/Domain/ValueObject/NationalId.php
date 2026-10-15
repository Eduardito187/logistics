<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Fleet\Domain\Exception\FleetException;

/**
 * Documento de identidad (CI / carné) de una persona de la flota. Se guarda tal cual (solo se recorta
 * espacio), sin asumir un formato nacional rígido: distintos extranjeros pueden traer formatos propios.
 */
final readonly class NationalId
{
    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw FleetException::because('El documento de identidad no puede estar vacío.');
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
