<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Inbound\Application\Query\ListAsns\ListAsns;
use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Presentation\Http\ApiController;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Lista ASNs (GET /v1/inbound/asn) filtrando por seller, almacén, estado y/o fecha de cita.
 */
final class ListAsnsController extends ApiController
{
    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            /** @var list<AsnView> $asns */
            $asns = $this->queries->ask(new ListAsns(
                sellerId: $this->filter($request, 'seller_id'),
                warehouseId: $this->filter($request, 'warehouse_id'),
                status: $this->filter($request, 'status'),
                appointmentDate: $this->filter($request, 'appointment_date'),
            ));

            return $this->data(array_map(static fn (AsnView $asn): array => $asn->toArray(), $asns));
        });
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = $request->string($key)->toString();

        return $value !== '' ? $value : null;
    }
}
