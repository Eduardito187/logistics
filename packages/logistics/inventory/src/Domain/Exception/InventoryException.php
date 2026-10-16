<?php

declare(strict_types=1);

namespace Logistics\Inventory\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de inventario: cantidad negativa, reservar más de lo disponible,
 * liberar/pick más de lo reservado, transferir al mismo almacén, ajuste que deja el stock en negativo, etc.
 */
final class InventoryException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
