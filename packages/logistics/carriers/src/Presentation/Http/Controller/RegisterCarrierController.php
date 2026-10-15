<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Carriers\Application\Command\RegisterCarrier\RegisterCarrier;
use Logistics\Carriers\Presentation\Http\ApiController;
use Logistics\Carriers\Presentation\Http\Request\RegisterCarrierRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Registra un transportista tercero nuevo despachando el comando RegisterCarrier al bus CQRS.
 *
 * El id lo genera el dominio dentro del handler; el comando no devuelve valor, así que la respuesta es
 * un 202 de acuse con los datos aceptados.
 */
final class RegisterCarrierController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(RegisterCarrierRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<array{type: string, reference: string}> $documents */
            $documents = array_values($request->array('documents'));

            $this->commands->dispatch(new RegisterCarrier(
                name: $request->string('name')->toString(),
                taxId: $request->string('tax_id')->toString(),
                documents: $documents,
            ));

            return $this->accepted([
                'name' => $request->string('name')->toString(),
                'tax_id' => $request->string('tax_id')->toString(),
            ]);
        });
    }
}
