<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del seguimiento de un envío. Por dentro es un UUID v7 (ver núcleo compartido).
 *
 * Es la clave interna del agregado; la clave pública que viaja en el enlace de rastreo es el token,
 * que es opaco y no adivinable.
 */
final readonly class TrackingId extends AbstractId {}
