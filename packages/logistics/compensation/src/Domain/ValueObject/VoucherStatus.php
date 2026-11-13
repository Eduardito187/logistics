<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

/**
 * Estado del ciclo de vida de un vale.
 *
 * issued → redeemed (canjeado, un solo uso) | issued → expired (venció) | issued → revoked (anulado).
 */
enum VoucherStatus: string
{
    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
