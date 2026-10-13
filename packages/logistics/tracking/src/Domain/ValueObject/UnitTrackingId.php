<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del seguimiento de un BULTO (unidad física). Por dentro es un UUID v7.
 *
 * Es la clave interna del agregado UnitTracking; el bulto del mundo real se referencia por su id
 * string (unitId), el mismo que emiten los eventos de execution, sin acoplar el agregado de shipments.
 */
final readonly class UnitTrackingId extends AbstractId {}
