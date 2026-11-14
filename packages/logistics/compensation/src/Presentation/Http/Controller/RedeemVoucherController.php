<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Compensation\Application\Command\RedeemVoucher\RedeemVoucher;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Compensation\Presentation\Http\Request\RedeemVoucherRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * POST /integrations/vouchers/{code}/redeem — canje (un solo uso) del vale desde el POS.
 *
 * Idempotente por `sale_ref`: reintentar el mismo canje con la misma referencia vuelve a responder 202 sin
 * descontar otra vez. Un segundo canje con OTRA referencia ⇒ 409 (ya canjeado). Vale vencido ⇒ 410;
 * anulado / de otro titular ⇒ 409; código inexistente o mal formado ⇒ 404 genérico; monto inválido ⇒ 422.
 */
final class RedeemVoucherController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RedeemVoucherRequest $request, string $code): JsonResponse
    {
        $email = $request->filled('customer_email') ? (string) $request->string('customer_email') : null;

        try {
            $this->commands->dispatch(new RedeemVoucher(
                code: $code,
                channel: $this->channel($request),
                saleRef: (string) $request->string('sale_ref'),
                amountUsedCents: $request->integer('amount_used_cents'),
                customerEmail: $email,
            ));
        } catch (CompensationException $e) {
            return $this->voucherProblem($e, $request);
        }

        return $this->accepted(['redeemed' => true, 'sale_ref' => (string) $request->string('sale_ref')]);
    }

    private function channel(RedeemVoucherRequest $request): string
    {
        foreach (['channel', 'store_code'] as $key) {
            if ($request->filled($key)) {
                return (string) $request->string($key);
            }
        }

        return 'pos';
    }
}
