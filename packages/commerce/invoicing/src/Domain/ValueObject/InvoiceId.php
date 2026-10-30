<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una factura. Por dentro es un UUID v7 (ver AbstractId).
 *
 * Tiparla evita confundir el id de una factura con el de la orden que factura: el type system lo atrapa.
 */
final readonly class InvoiceId extends AbstractId {}
