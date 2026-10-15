<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una inspección pre-salida (checklist realizado). UUID v7 por dentro.
 */
final readonly class PreTripInspectionId extends AbstractId {}
