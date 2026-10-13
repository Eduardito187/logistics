<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Access\Domain\Exception\AccessException;

/**
 * Clave estable de un permiso, con granularidad "<grupo>.<accion>" en minúsculas
 * (p. ej. "shipments.read", "access.roles.manage"). El comodín "*" concede todo.
 *
 * Es el contrato de texto que comparten roles, tokens de integración y el resolvedor de permisos:
 * siempre se compara por su string, nunca por el objeto. Inmutable y validado en el constructor.
 */
final readonly class PermissionKey
{
    public const WILDCARD = '*';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === self::WILDCARD) {
            return new self(self::WILDCARD);
        }

        // grupo(.subgrupo)*.accion — segmentos alfanuméricos separados por punto, mínimo dos.
        if (preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)+$/', $normalized) !== 1) {
            throw AccessException::because("Clave de permiso inválida: {$value}");
        }

        return new self($normalized);
    }

    public function isWildcard(): bool
    {
        return $this->value === self::WILDCARD;
    }

    /**
     * Grupo del permiso (el primer segmento): "shipments.read" → "shipments". El comodín no tiene grupo.
     */
    public function group(): string
    {
        if ($this->isWildcard()) {
            return self::WILDCARD;
        }

        return explode('.', $this->value)[0];
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
