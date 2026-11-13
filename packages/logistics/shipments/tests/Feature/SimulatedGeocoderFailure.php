<?php

declare(strict_types=1);

namespace Logistics\Shipments\Tests\Feature;

use RuntimeException;

/**
 * Fallo simulado del geocoder interno: debe NO invocarse en un acierto de caché.
 */
final class SimulatedGeocoderFailure extends RuntimeException {}
