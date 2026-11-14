<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado del ciclo de vida de un vale.
 *
 * issued → redeemed (canjeado, un solo uso) | issued → expired (venció) | issued → revoked (anulado).
 */
enum VoucherStatus: string
{
    use CaseInsensitiveEnum;

    case Issued = 'issued';
    case Redeemed = 'redeemed';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
