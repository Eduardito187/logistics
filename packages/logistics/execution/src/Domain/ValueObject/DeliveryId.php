<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de la entrega (una por envío). Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class DeliveryId extends AbstractId {}
