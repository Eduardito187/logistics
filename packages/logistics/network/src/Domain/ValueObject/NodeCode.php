<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Network\Domain\Exception\NetworkException;

/**
 * Código corto, único y legible de un nodo (p. ej. "CD-LP-01", "T-SCZ-03").
 *
 * Es el identificador que ven las personas y que viaja en la etiqueta QR (campo de origen/destino),
 * por eso comparte el mismo alfabeto que el nodo en labels: mayúsculas, dígitos y guiones, sin "|"
 * ni espacios. El UUID es la clave interna; el código es la clave humana.
 */
final readonly class NodeCode
{
    private const string PATTERN = '/^[A-Z0-9]+(-[A-Z0-9]+)+$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $length = strlen($normalized);
        if ($length < 3 || $length > 20) {
            throw NetworkException::because("código de nodo fuera de longitud (3..20): {$value}");
        }
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw NetworkException::because("código de nodo con formato inválido: {$value}");
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
