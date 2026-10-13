<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta app: lista los pedidos recibidos, con filtros opcionales por estado y fuente.
 *
 * Lee vía QueryBus (ListShipments → list<ShipmentView>). La validación es inline por ser filtros
 * simples de query string.
 */
final class ListShipmentsController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $statuses = array_map(static fn (ShipmentStatus $status): string => $status->value, ShipmentStatus::cases());
        $sources = array_map(static fn (OrderSource $source): string => $source->value, OrderSource::cases());

        /** @var array{status?: string|null, source?: string|null} $filters */
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in($statuses)],
            'source' => ['nullable', 'string', Rule::in($sources)],
        ]);

        /** @var list<ShipmentView> $views */
        $views = $this->queryBus->ask(new ListShipments(
            status: $filters['status'] ?? null,
            source: $filters['source'] ?? null,
        ));

        return $this->data(array_map(static fn (ShipmentView $view): array => $view->toArray(), $views));
    }
}
