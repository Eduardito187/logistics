<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetGoodsReceipt;

use Logistics\Inbound\Application\ReadModel\GoodsReceiptView;
use Logistics\Inbound\Domain\Contract\GoodsReceiptRepository;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la recepción de un ASN, o null si todavía no se registró.
 *
 * @implements QueryHandler<GetGoodsReceipt, GoodsReceiptView|null>
 */
final readonly class GetGoodsReceiptHandler implements QueryHandler
{
    public function __construct(
        private GoodsReceiptRepository $receipts,
    ) {}

    public function handle(Query $query): ?GoodsReceiptView
    {
        assert($query instanceof GetGoodsReceipt);

        $receipt = $this->receipts->findByAsnId(AsnId::fromString($query->asnId));

        return $receipt !== null ? GoodsReceiptView::fromDomain($receipt) : null;
    }
}
