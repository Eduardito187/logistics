<?php

declare(strict_types=1);

namespace Logistics\Shipments\Tests\Feature;

use RuntimeException;

/**
 * Falla al sembrar: el envío recién recibido no apareció en el repositorio.
 */
final class LineMeasurementsShipmentNotFound extends RuntimeException {}
