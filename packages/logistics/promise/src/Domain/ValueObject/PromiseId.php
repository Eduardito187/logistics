<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de la promesa de entrega. Por dentro es un UUID v7 (ver núcleo compartido).
 */
final readonly class PromiseId extends AbstractId {}
