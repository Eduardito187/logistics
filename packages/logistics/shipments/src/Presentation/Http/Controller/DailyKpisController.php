<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\GetDailyKpis\GetDailyKpis;
use Logistics\Shipments\Application\ReadModel\DailyKpisView;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;
use Logistics\Shipments\Presentation\Http\Support\ReadsOperationalDates;

/**
 * Puerta app: GET /kpis/daily — KPIs operativos del día para chofer, supervisor y tienda.
 *
 * Reemplaza calcular en el teléfono bajando todas las entregas: agregados SQL de solo lectura con caché
 * corta (ver DailyKpisView para las definiciones de cada indicador).
 *
 * Query: `date` (YYYY-MM-DD o fecha-hora ISO; por defecto HOY en America/La_Paz) y, solo para supervisor
 * y admin, `node_id`, `vehicle_id` y `driver_id` opcionales.
 *
 * Alcance por rol (server-side, no se confía en la app):
 *  - supervisor / admin → toda la flota (`fleet`) o el nodo pedido (`node`), con los filtros dados;
 *  - chofer → solo lo suyo (`driver`, por `users.driver_id`), ignora los filtros;
 *  - tienda → su nodo asignado (`node`, por `users.node_id`), ignora los filtros;
 *  - otros roles → 403.
 * Una cuenta de chofer/tienda sin chofer/nodo vinculado recibe los KPIs en cero (nunca datos ajenos).
 */
final class DailyKpisController
{
    use JsonEnvelope;
    use ReadsOperationalDates;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->normalizeDateParameters($request, ['date']);

        /** @var array{date?: string|null, node_id?: string|null, vehicle_id?: string|null, driver_id?: string|null} $filters */
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'node_id' => ['nullable', 'uuid'],
            'vehicle_id' => ['nullable', 'uuid'],
            'driver_id' => ['nullable', 'uuid'],
        ]);

        $date = $filters['date'] ?? $this->operationalToday();

        $query = $this->scopedQuery($request->user(), $date, $filters);
        if ($query === null) {
            return ProblemDetails::fromCode(
                ErrorCode::InsufficientRole,
                'Los KPIs del día están disponibles para choferes, supervisores y tiendas.',
            )->withInstance($request->getRequestUri())->toResponse($request);
        }

        /** @var DailyKpisView $kpis */
        $kpis = $this->queryBus->ask($query);

        return $this->data($kpis->toArray());
    }

    /**
     * Arma la consulta con el alcance que corresponde al rol del usuario; null si el rol no tiene acceso.
     *
     * @param array{date?: string|null, node_id?: string|null, vehicle_id?: string|null, driver_id?: string|null} $filters
     */
    private function scopedQuery(mixed $user, string $date, array $filters): ?GetDailyKpis
    {
        if (!$user instanceof User) {
            return null;
        }

        if ($user->hasRole(Role::Admin) || $user->hasRole(Role::Supervisor)) {
            $nodeId = $filters['node_id'] ?? null;

            return new GetDailyKpis(
                date: $date,
                scope: $nodeId !== null ? 'node' : 'fleet',
                nodeId: $nodeId,
                vehicleId: $filters['vehicle_id'] ?? null,
                driverId: $filters['driver_id'] ?? null,
            );
        }

        if ($user->hasRole(Role::Chofer)) {
            $driverId = $user->driver_id;
            $linked = is_string($driverId) && $driverId !== '';

            return new GetDailyKpis(
                date: $date,
                scope: 'driver',
                driverId: $linked ? $driverId : null,
                emptyScope: !$linked,
            );
        }

        if ($user->hasRole(Role::Tienda)) {
            $nodeId = $user->node_id;
            $linked = is_string($nodeId) && $nodeId !== '';

            return new GetDailyKpis(
                date: $date,
                scope: 'node',
                nodeId: $linked ? $nodeId : null,
                emptyScope: !$linked,
            );
        }

        return null;
    }
}
