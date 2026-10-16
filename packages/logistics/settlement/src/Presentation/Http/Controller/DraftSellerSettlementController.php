<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Settlement\Application\Command\DraftSellerSettlement\DraftSellerSettlement;
use Logistics\Settlement\Presentation\Http\ApiController;
use Logistics\Settlement\Presentation\Http\Request\DraftSellerSettlementRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Arma el borrador de una liquidación de seller agregando sus despachos del periodo (comando
 * DraftSellerSettlement).
 */
final class DraftSellerSettlementController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(DraftSellerSettlementRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $sellerId = $request->string('seller_id')->toString();
            $currency = $request->has('currency')
                ? $request->string('currency')->toString()
                : 'BOB';

            $this->commands->dispatch(new DraftSellerSettlement(
                sellerId: $sellerId,
                periodFrom: $request->string('period_from')->toString(),
                periodTo: $request->string('period_to')->toString(),
                commissionBasisPoints: $request->integer('commission_basis_points'),
                fixedFeeCents: $request->integer('fixed_fee_cents'),
                currency: $currency,
            ));

            return $this->accepted(['seller_id' => $sellerId]);
        });
    }
}
