<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\GetSellerStatement;

use Logistics\Settlement\Application\ReadModel\SellerStatementView;
use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\ValueObject\Money;
use Logistics\Settlement\Domain\ValueObject\SettlementStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Arma el estado de cuenta de un seller: agrega sus liquidaciones (bruto, fees, neto, pagado vs. pendiente)
 * y lista cada una. El neto pagado es el de las liquidaciones en estado Paid; el pendiente, las demás.
 *
 * @implements QueryHandler<GetSellerStatement, SellerStatementView>
 */
final readonly class GetSellerStatementHandler implements QueryHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private SellerDirectory $sellers,
    ) {}

    public function handle(Query $query): SellerStatementView
    {
        assert($query instanceof GetSellerStatement);

        $settlements = $this->settlements->list(
            $query->sellerId,
            null,
            $query->periodFrom,
            $query->periodTo,
        );
        $sellerName = $this->sellers->nameById($query->sellerId);

        // La moneda del statement es la de la primera liquidación; sin liquidaciones, BOB por defecto.
        $currency = $settlements === [] ? Money::DEFAULT_CURRENCY : $settlements[0]->net()->currency;

        $grossCents = 0;
        $feesTotalCents = 0;
        $netCents = 0;
        $paidCents = 0;
        $pendingCents = 0;
        $views = [];

        foreach ($settlements as $settlement) {
            $grossCents += $settlement->gross()->cents;
            $feesTotalCents += $settlement->fees()->total()->cents;
            $netCents += $settlement->net()->cents;

            if ($settlement->status() === SettlementStatus::Paid) {
                $paidCents += $settlement->net()->cents;
            } else {
                $pendingCents += $settlement->net()->cents;
            }

            $views[] = SettlementView::fromDomain($settlement, $sellerName)->toArray();
        }

        return SellerStatementView::of(
            $query->sellerId,
            $sellerName,
            count($settlements),
            $grossCents,
            $feesTotalCents,
            $netCents,
            $paidCents,
            $pendingCents,
            $currency,
            $views,
        );
    }
}
