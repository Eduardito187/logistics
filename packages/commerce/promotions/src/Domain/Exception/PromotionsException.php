<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de promociones: valor porcentual fuera de 0..10000, código de
 * cupón vacío, canal inválido, ventana de fechas malformada, etc.
 */
final class PromotionsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
