<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una orden comercial. Por dentro es un UUID v7 (ver AbstractId). Se usa además
 * como `externalId` del envío en el puente a fulfillment (idempotencia por orden).
 */
final readonly class OrderId extends AbstractId {}
