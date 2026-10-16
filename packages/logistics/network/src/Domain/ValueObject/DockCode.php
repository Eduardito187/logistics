<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Network\Domain\Exception\NetworkException;

/**
 * Código corto y legible de un andén de recepción, único dentro de su almacén (p. ej. "DOCK-01", "A3").
 *
 * El UUID es la clave interna; el código es la clave humana que el operador usa para referirse al andén.
 * Alfabeto: mayúsculas, dígitos y guiones, sin espacios ni "|".
 */
final readonly class DockCode
{
    private const string PATTERN = '/^[A-Z0-9]+(-[A-Z0-9]+)*$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $length = strlen($normalized);
        if ($length < 1 || $length > 20) {
            throw NetworkException::because("código de andén fuera de longitud (1..20): {$value}");
        }
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw NetworkException::because("código de andén con formato inválido: {$value}");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
