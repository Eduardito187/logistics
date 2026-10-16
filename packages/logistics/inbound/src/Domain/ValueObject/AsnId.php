<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un ASN / cita de recepción. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class AsnId extends AbstractId {}
