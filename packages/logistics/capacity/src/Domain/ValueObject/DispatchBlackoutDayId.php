<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un día bloqueado de despacho (feriado u otro motivo). Por dentro es un UUID v7.
 */
final readonly class DispatchBlackoutDayId extends AbstractId {}
