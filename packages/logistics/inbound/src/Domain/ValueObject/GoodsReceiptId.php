<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un goods receipt (recepción física contra un ASN). Por dentro es un UUID v7.
 */
final readonly class GoodsReceiptId extends AbstractId {}
