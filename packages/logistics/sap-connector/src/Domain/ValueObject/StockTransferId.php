<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada del traspaso de stock hacia SAP. Por dentro es un UUID v7.
 */
final readonly class StockTransferId extends AbstractId {}
