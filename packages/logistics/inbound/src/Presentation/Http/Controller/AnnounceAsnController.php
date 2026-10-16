<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Inbound\Application\Command\AnnounceAsn\AnnounceAsn;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Inbound\Presentation\Http\Request\AnnounceAsnRequest;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Anuncia un ASN despachando el comando AnnounceAsn al bus CQRS (puerta `integrations`: lo hace el seller
 * por API). El id lo genera el dominio; la respuesta es un 202 de acuse.
 */
final class AnnounceAsnController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
    ) {}

    public function __invoke(AnnounceAsnRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<array{sku: string, expected_quantity: int}> $lines */
            $lines = array_map(
                static fn (array $line): array => [
                    'sku' => (string) $line['sku'],
                    'expected_quantity' => (int) $line['expected_quantity'],
                ],
                array_values($request->array('lines')),
            );

            $this->commands->dispatch(new AnnounceAsn(
                sellerId: $request->string('seller_id')->toString(),
                warehouseId: $request->string('warehouse_id')->toString(),
                lines: $lines,
            ));

            return $this->accepted([
                'seller_id' => $request->string('seller_id')->toString(),
                'warehouse_id' => $request->string('warehouse_id')->toString(),
            ]);
        });
    }
}
