<?php

declare(strict_types=1);

namespace Logistics\Shipments\Tests\Unit;

use RuntimeException;

/**
 * Caída simulada del lector de catálogo (la cascada debe degradar a los defaults sin propagarla).
 */
final class FakeCatalogReaderOutage extends RuntimeException {}
