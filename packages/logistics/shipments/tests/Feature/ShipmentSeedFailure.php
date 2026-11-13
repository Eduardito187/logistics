<?php

declare(strict_types=1);

namespace Logistics\Shipments\Tests\Feature;

use RuntimeException;

/**
 * Falla al sembrar: el envío esperado no apareció en el repositorio tras el ingreso.
 */
final class ShipmentSeedFailure extends RuntimeException {}
