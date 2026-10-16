<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Domain\Exception;

use RuntimeException;

/**
 * Error del portal del seller. PHP puro (sin Laravel): el mapeo a HTTP lo hace la capa de Presentación.
 */
final class SellerPortalException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
