<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un pago. Por dentro es un UUID v7 (ver AbstractId).
 *
 * Tiparla evita confundir el id de un pago con el de la orden a la que cobra: el type system lo atrapa.
 */
final readonly class PaymentId extends AbstractId {}
