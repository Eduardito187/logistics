<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Exception;

use DomainException;

/**
 * Se intentó emitir dos veces una etiqueta con el mismo `id`.
 *
 * El `id` del payload es único por bulto físico: que aparezca dos veces significa una reimpresión o un
 * error de emisión. Es una regla de negocio dura (ADR 004: detección de duplicados y revocación por
 * reimpresión), por eso corta con excepción y no se traga el segundo registro.
 */
final class DuplicateLabelException extends DomainException
{
    public static function forLabelId(string $labelId): self
    {
        return new self("La etiqueta ya fue emitida: {$labelId}");
    }
}
