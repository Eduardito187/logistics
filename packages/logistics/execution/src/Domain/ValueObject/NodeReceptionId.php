<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una recepción en nodo (conciliación de un envío que llega a un nodo). Por dentro
 * es un UUID v7.
 */
final readonly class NodeReceptionId extends AbstractId {}
