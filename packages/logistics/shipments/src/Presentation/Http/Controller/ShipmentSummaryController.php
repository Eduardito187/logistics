<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\GetShipmentSummary\GetShipmentSummary;
use Logistics\Shipments\Application\ReadModel\ShipmentSummaryView;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ReadsOperationalDates;

/**
 * Puerta app: GET /shipments/summary — resumen agregado del tablero del supervisor (pedido #16).
 *
 * Reemplaza bajar la colección histórica completa para contar estados en el teléfono: devuelve conteos
 * por estado del envío y de la entrega en ejecución, planes publicados, alertas abiertas y bultos, con
 * agregados SQL y caché corta (segundos).
 *
 * Fechas: `date` (un día) o `date_from`/`date_to` (rango inclusive) acotan los envíos por fecha PROMETIDA;
 * sin fecha, cuenta todos los envíos. Planes y alertas se acotan al mismo período, o a HOY (La Paz) si no
 * se manda fecha. Filtros opcionales: `destination_node_id` y `delivery_mode`.
 *
 * Alcance por tienda (server-side), igual que el listado: el rol Tienda queda acotado a su nodo asignado.
 */
final class ShipmentSummaryController
{
    use JsonEnvelope;
    use ReadsOperationalDates;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->normalizeDateParameters($request, ['date', 'date_from', 'date_to']);

        $modes = array_map(static fn (DeliveryMode $mode): string => $mode->value, DeliveryMode::cases());

        /** @var array{date?: string|null, date_from?: string|null, date_to?: string|null, destination_node_id?: string|null, delivery_mode?: string|null} $filters */
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'destination_node_id' => ['nullable', 'uuid'],
            'delivery_mode' => ['nullable', 'string', Rule::in($modes)],
        ]);

        // `date` (un día) tiene prioridad sobre el rango.
        $date = $filters['date'] ?? null;
        $dateFrom = $date ?? ($filters['date_from'] ?? null);
        $dateTo = $date ?? ($filters['date_to'] ?? null);

        $today = $this->operationalToday();
        $periodFrom = $dateFrom ?? $dateTo ?? $today;
        $periodTo = $dateTo ?? $dateFrom ?? $today;

        $destinationNodeId = $filters['destination_node_id'] ?? null;

        $user = $request->user();
        if ($user instanceof User && $user->hasRole(Role::Tienda)) {
            $assignedNode = $user->node_id;
            if (!is_string($assignedNode) || $assignedNode === '') {
                // Tienda sin nodo: nunca ve envíos ajenos → resumen en cero (sin consultar).
                $assignedNode = '00000000-0000-0000-0000-000000000000';
            }
            $destinationNodeId = $assignedNode;
        }

        /** @var ShipmentSummaryView $summary */
        $summary = $this->queryBus->ask(new GetShipmentSummary(
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            deliveryDateFrom: $dateFrom,
            deliveryDateTo: $dateTo,
            destinationNodeId: $destinationNodeId,
            deliveryMode: $filters['delivery_mode'] ?? null,
        ));

        return $this->data($summary->toArray());
    }
}
