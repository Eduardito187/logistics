<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un documento de flota (con archivo y vencimiento). UUID v7 por dentro.
 */
final readonly class FleetDocumentId extends AbstractId {}
