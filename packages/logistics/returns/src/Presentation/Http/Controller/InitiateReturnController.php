<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Returns\Application\Command\InitiateReturn\InitiateReturn;
use Logistics\Returns\Presentation\Http\ApiController;
use Logistics\Returns\Presentation\Http\Request\InitiateReturnRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Inicia una devolución despachando InitiateReturn al bus CQRS (puerta `integrations`). El id lo genera el
 * dominio; la respuesta es un 202 de acuse (la escritura confirma vía outbox).
 */
final class InitiateReturnController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(InitiateReturnRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<array{sku: string, quantity: int, reason: string}> $lines */
            $lines = array_map(
                static fn (array $line): array => [
                    'sku' => (string) $line['sku'],
                    'quantity' => (int) $line['quantity'],
                    'reason' => (string) $line['reason'],
                ],
                array_values($request->array('lines')),
            );

            $ownerPartyId = $request->input('owner_party_id');

            $this->commands->dispatch(new InitiateReturn(
                originalReference: $request->string('original_reference')->toString(),
                warehouseId: $request->string('warehouse_id')->toString(),
                kind: $request->string('kind')->toString(),
                lines: $lines,
                ownerPartyId: is_string($ownerPartyId) && $ownerPartyId !== '' ? $ownerPartyId : null,
            ));

            return $this->accepted([
                'original_reference' => $request->string('original_reference')->toString(),
            ]);
        });
    }
}
