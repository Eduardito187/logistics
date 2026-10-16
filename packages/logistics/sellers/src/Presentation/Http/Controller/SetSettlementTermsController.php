<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Sellers\Application\Command\SetSettlementTerms\SetSettlementTerms;
use Logistics\Sellers\Domain\ValueObject\Money;
use Logistics\Sellers\Presentation\Http\ApiController;
use Logistics\Sellers\Presentation\Http\Request\SetSettlementTermsRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Pacta/actualiza los términos de liquidación de un seller despachando SetSettlementTerms al bus CQRS.
 */
final class SetSettlementTermsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(SetSettlementTermsRequest $request, string $sellerId): JsonResponse
    {
        return $this->guard(function () use ($request, $sellerId): JsonResponse {
            $currency = $request->string('currency')->toString();

            $this->commands->dispatch(new SetSettlementTerms(
                sellerId: $sellerId,
                commissionBasisPoints: $request->integer('commission_basis_points'),
                fixedFeeCents: $request->integer('fixed_fee_cents'),
                currency: $currency !== '' ? $currency : Money::DEFAULT_CURRENCY,
                paymentCycle: $request->string('payment_cycle')->toString(),
            ));

            return $this->accepted(['seller_id' => $sellerId]);
        });
    }
}
