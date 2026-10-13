<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un tramo/corredor de la red.
 *
 * Hereda el comportamiento (generate/fromString/fromUuid/equals) de AbstractId; solo existe para
 * que el type system distinga un LegId del id de cualquier otro agregado.
 */
final readonly class LegId extends AbstractId {}
