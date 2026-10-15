<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Access\Domain\Exception\AccessException;

/**
 * Acción auditada: clave estable "sujeto.verbo" en minúsculas (p. ej. "user.suspended",
 * "role.permissions_changed", "token.revoked").
 *
 * Es un contrato legible por máquina: los filtros del admin y los consumidores del outbox se apoyan en
 * ella, por eso el formato es fijo y no admite espacios ni mayúsculas. La etiqueta visible en inglés la
 * resuelve la capa de presentación (regla 5), no el dominio.
 */
final readonly class AuditAction
{
    private const PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/';

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw AccessException::because("Acción de auditoría inválida: {$value}");
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
