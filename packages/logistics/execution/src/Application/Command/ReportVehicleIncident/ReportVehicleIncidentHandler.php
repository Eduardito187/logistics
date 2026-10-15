<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ReportVehicleIncident;

use DateTimeImmutable;
use Logistics\Execution\Domain\Contract\VehicleIncidentRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\VehicleIncident;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\IncidentType;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Maneja el reporte de incidente del chofer y aplica la reacción AUTOMÁTICA sobre la ruta.
 *
 * El handler orquesta solo lo que es de Ejecución (la ruta del chofer): deja constancia inmutable del
 * incidente y reprograma SOLO las paradas que seguían PENDIENTES —las entregadas o fallidas no se
 * tocan—. La gravedad decide la reacción:
 *
 * - MAJOR (accidente/avería grave): la ruta pasa a SUSPENDIDA y sus paradas pendientes se marcan como
 *   REPROGRAMADAS; el envío afectado se replanifica para el DÍA SIGUIENTE. Además, el evento de dominio
 *   deja al camión fuera de servicio (flota) y avisa a clientes/supervisor por el outbox.
 * - MINOR (leve): se empujan las ETAs de las paradas pendientes por los minutos de retraso configurados
 *   (la ruta continúa). Clientes afectados reciben "en camino con retraso".
 *
 * La reacción en otros contextos (flota fuera de servicio, replanificación, notificaciones) viaja por el
 * outbox a través del evento execution.vehicle_incident_reported: nada de I/O síncrono acá (principio 4).
 * El save de la ruta, el del incidente y el append al outbox corren en la misma transacción que abre el
 * decorador transaccional del CommandBus.
 *
 * Idempotente por idempotencyKey: el reenvío del mismo reporte de la app offline no duplica el hecho.
 *
 * @implements CommandHandler<ReportVehicleIncident>
 */
final class ReportVehicleIncidentHandler implements CommandHandler
{
    public function __construct(
        private readonly VehicleRouteRepository $routes,
        private readonly VehicleIncidentRepository $incidents,
        private readonly RecordDomainEvents $recordEvents,
        private readonly int $minorDelayMinutes,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReportVehicleIncident) {
            throw ExecutionException::because('ReportVehicleIncidentHandler solo maneja ReportVehicleIncident');
        }

        // Idempotencia: si ya se registró este reporte, no se repite la reacción.
        if ($this->incidents->existsByIdempotencyKey($command->idempotencyKey)) {
            return;
        }

        $severity = IncidentSeverity::from($command->severity);
        $type = IncidentType::from($command->type);

        $route = $this->resolveRoute($command);

        $occurredAt = $command->occurredAt !== null && trim($command->occurredAt) !== ''
            ? new DateTimeImmutable($command->occurredAt)
            : new DateTimeImmutable;

        // Minutos de retraso solo para MINOR; para MAJOR no aplica (se reprograma al día siguiente).
        $delayMinutes = $severity === IncidentSeverity::Minor ? $this->minorDelayMinutes : null;

        // Reacción sobre la ruta: reprograma/retrasa SOLO las paradas pendientes y devuelve sus envíos.
        $affected = $route->reportIncident($severity, $delayMinutes ?? 0, $occurredAt);

        $this->routes->save($route);

        // Para MAJOR, los envíos afectados se replanifican al día siguiente de la fecha de la ruta.
        $rescheduleDate = $severity === IncidentSeverity::Major
            ? $route->date()->nextDay()
            : null;

        $location = $command->lat !== null && $command->lng !== null
            ? GeoPoint::of($command->lat, $command->lng)
            : null;

        $incident = VehicleIncident::report(
            $this->incidents->nextIdentity(),
            $route->id(),
            $route->vehicleId(),
            $severity,
            $type,
            $command->note,
            $location,
            DeviceContext::of(
                $command->deviceId !== '' ? $command->deviceId : 'unknown-device',
                $command->userId !== '' ? $command->userId : 'unknown-user',
            ),
            $affected,
            $rescheduleDate,
            $delayMinutes,
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->incidents->save($incident);

        $this->recordEvents->record($incident->releaseEvents(), 'execution.vehicle_incident');
    }

    private function resolveRoute(ReportVehicleIncident $command): VehicleRoute
    {
        if ($command->routeId !== null && trim($command->routeId) !== '') {
            $route = $this->routes->findById(RouteId::fromString($command->routeId));
            if ($route === null) {
                throw ExecutionException::because("no existe la ruta {$command->routeId}");
            }
            if ($route->vehicleId() !== $command->vehicleId) {
                throw ExecutionException::because('la ruta no pertenece al vehículo del reporte');
            }

            return $route;
        }

        $route = $this->routes->findOpenByVehicleId($command->vehicleId);
        if ($route === null) {
            throw ExecutionException::because("el vehículo {$command->vehicleId} no tiene ruta abierta para reportar un incidente");
        }

        return $route;
    }
}
