<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un nodo de la red (CD, tienda, punto de recojo).
 *
 * Hereda el comportamiento (generate/fromString/fromUuid/equals) de AbstractId; solo existe para
 * que el type system distinga un NodeId del id de cualquier otro agregado.
 */
final readonly class NodeId extends AbstractId {}
