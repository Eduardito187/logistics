<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Access\Domain\Exception\AccessException;

/**
 * Clave estable (slug) de un rol: minúsculas, dígitos y guiones ("warehouse-supervisor").
 *
 * Es la referencia de texto con la que un usuario declara sus roles; la identidad interna del rol es
 * su RoleId (UUID v7), pero la clave es lo que viaja en asignaciones y tokens por ser legible y estable.
 */
final readonly class RoleKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $normalized) !== 1) {
            throw AccessException::because("Clave de rol inválida: {$value}");
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
