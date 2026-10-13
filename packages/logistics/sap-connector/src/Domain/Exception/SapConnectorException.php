<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del conector SAP: un dato de traducción inválido (material, centro,
 * almacén), un mapeo inexistente o una transición de estado no permitida en un traspaso.
 */
final class SapConnectorException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
