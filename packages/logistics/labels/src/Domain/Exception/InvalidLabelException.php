<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Exception;

use DomainException;

/**
 * Etiqueta mal formada: contenido de QR ilegible, campos fuera de formato o firma mal codificada.
 *
 * Una firma que no verifica NO lanza esta excepción: eso lo informa el verificador como resultado,
 * porque en el andén es un caso de negocio esperable (etiqueta falsa o editada), no un error.
 */
final class InvalidLabelException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Etiqueta inválida: {$reason}");
    }
}
