<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Compensation\Application\Command\HoldVoucher\HoldVoucher;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Compensation\Presentation\Http\Request\HoldVoucherRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * POST /integrations/vouchers/{code}/hold — reserva temporal del vale para una venta del POS.
 *
 * 202 si se reservó; 409 si ya estaba reservado por otra venta vigente o no es canjeable; 404 si el código
 * no existe o está mal formado (genérico). El ttl por defecto sale de la política del vale.
 */
final class HoldVoucherController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(HoldVoucherRequest $request, string $code): JsonResponse
    {
        $defaultTtl = (int) config('logistics-compensation.voucher.hold_ttl_minutes', 10);
        $ttl = $request->filled('ttl_minutes') ? $request->integer('ttl_minutes') : $defaultTtl;

        try {
            $this->commands->dispatch(new HoldVoucher(
                code: $code,
                channel: $this->channel($request),
                ttlMinutes: $ttl,
            ));
        } catch (CompensationException $e) {
            return $this->voucherProblem($e, $request);
        }

        return $this->accepted(['held' => true, 'ttl_minutes' => $ttl]);
    }

    private function channel(HoldVoucherRequest $request): string
    {
        foreach (['channel', 'store_code'] as $key) {
            if ($request->filled($key)) {
                return (string) $request->string($key);
            }
        }

        return 'pos';
    }
}
