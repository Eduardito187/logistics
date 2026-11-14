<?php

declare(strict_types=1);

namespace Logistics\Planning\Tests\Feature;

use RuntimeException;

/**
 * Falla simulada del proveedor de geometría (ruteador caído) para probar la degradación elegante.
 */
final class SimulatedRouterFailure extends RuntimeException {}
