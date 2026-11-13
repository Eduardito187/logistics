<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Command\ExpireVouchers;

use Logistics\Shared\Application\Command\Command;

/**
 * Vence en lote los vales emitidos cuya fecha ya pasó (cron diario). `limit` acota el tamaño del lote.
 */
final readonly class ExpireVouchers implements Command
{
    public function __construct(
        public int $limit = 500,
    ) {}
}
