<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Sellers\Application\Command\RegisterSeller\RegisterSeller;
use Logistics\Sellers\Presentation\Http\ApiController;
use Logistics\Sellers\Presentation\Http\Request\RegisterSellerRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Da de alta un seller despachando el comando RegisterSeller al bus CQRS.
 *
 * El id lo genera el dominio dentro del handler; el comando no devuelve valor, así que la respuesta es
 * un 202 de acuse con los datos aceptados.
 */
final class RegisterSellerController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RegisterSellerRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $phone = $request->string('phone')->toString();

            $this->commands->dispatch(new RegisterSeller(
                legalName: $request->string('legal_name')->toString(),
                taxId: $request->string('tax_id')->toString(),
                contactName: $request->string('contact_name')->toString(),
                email: $request->string('email')->toString(),
                phone: $phone !== '' ? $phone : null,
            ));

            return $this->accepted([
                'legal_name' => $request->string('legal_name')->toString(),
                'tax_id' => $request->string('tax_id')->toString(),
            ]);
        });
    }
}
