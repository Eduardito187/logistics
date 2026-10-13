<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del mapeo de material (sku propio ↔ material SAP). Por dentro es un UUID v7.
 */
final readonly class MaterialMappingId extends AbstractId {}
