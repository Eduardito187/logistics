<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Compensation\Application\Command\ReleaseVoucherHold\ReleaseVoucherHold;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Presentation\Http\ApiController;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * POST /integrations/vouchers/{code}/release — libera la reserva (hold) del vale sin canjearlo (el POS
 * canceló la venta). 202 siempre que el vale exista; 404 genérico si el código no existe o está mal formado.
 */
final class ReleaseVoucherHoldController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(Request $request, string $code): JsonResponse
    {
        try {
            $this->commands->dispatch(new ReleaseVoucherHold(code: $code));
        } catch (CompensationException $e) {
            return $this->voucherProblem($e, $request);
        }

        return $this->accepted(['released' => true]);
    }
}
