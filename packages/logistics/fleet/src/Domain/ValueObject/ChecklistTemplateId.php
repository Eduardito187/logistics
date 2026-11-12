<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una plantilla de checklist pre-salida. UUID v7 por dentro.
 */
final readonly class ChecklistTemplateId extends AbstractId {}
