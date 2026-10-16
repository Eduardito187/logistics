<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un andén de recepción de un almacén central.
 *
 * Hereda el comportamiento (generate/fromString/fromUuid/equals) de AbstractId; solo existe para
 * que el type system distinga un ReceivingDockId del id de cualquier otro agregado.
 */
final readonly class ReceivingDockId extends AbstractId {}
