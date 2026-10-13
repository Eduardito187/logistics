<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Access\Domain\Exception\AccessException;

/**
 * Correo electrónico de un usuario. Se valida con filter_var y se normaliza a minúsculas para que la
 * unicidad sea insensible a mayúsculas ("User@Dismac.com" y "user@dismac.com" son el mismo usuario).
 */
final readonly class EmailAddress
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw AccessException::because("Correo electrónico inválido: {$value}");
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
