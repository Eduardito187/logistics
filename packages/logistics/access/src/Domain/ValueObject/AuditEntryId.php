<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una entrada de auditoría. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class AuditEntryId extends AbstractId {}
