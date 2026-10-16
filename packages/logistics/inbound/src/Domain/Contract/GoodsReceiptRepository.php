<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Contract;

use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;

/**
 * Puerto de persistencia del agregado GoodsReceipt. La implementación vive en Infrastructure.
 */
interface GoodsReceiptRepository
{
    public function nextIdentity(): GoodsReceiptId;

    /**
     * Upsert de la recepción por id (incluye sus líneas con QC).
     */
    public function save(GoodsReceipt $receipt): void;

    public function findById(GoodsReceiptId $id): ?GoodsReceipt;

    /**
     * La recepción asociada a un ASN, o null si todavía no se registró ninguna.
     */
    public function findByAsnId(AsnId $asnId): ?GoodsReceipt;
}
