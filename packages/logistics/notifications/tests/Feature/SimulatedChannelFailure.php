<?php

declare(strict_types=1);

namespace Logistics\Notifications\Tests\Feature;

use RuntimeException;

/**
 * Fallo simulado de un canal caído (sender doble): el breaker debe aislarlo.
 */
final class SimulatedChannelFailure extends RuntimeException {}
