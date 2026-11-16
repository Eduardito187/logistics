<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssembleDailyRoute;

use DateTimeImmutable;
use Exception;
use Logistics\Execution\Application\Contract\PlannedRouteStopsProvider;
use Logistics\Execution\Application\Contract\ShipmentRouteFactsReader;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Arma la ruta del día de un camión a partir de las paradas dadas, la persiste y publica sus eventos al
 * outbox. No hay I/O síncrono: el handoff hacia tracking/notificaciones ocurre por los eventos.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * Deja el id de la ruta generada en lastRouteId() para que el llamador lo use.
 *
 * @implements CommandHandler<AssembleDailyRoute>
 */
final class AssembleDailyRouteHandler implements CommandHandler
{
    private ?string $lastRouteId = null;

    public function __construct(
        private readonly VehicleRouteRepository $routes,
        private readonly RecordDomainEvents $recordEvents,
        private readonly PlannedRouteStopsProvider $plannedStops,
        private readonly ShipmentRouteFactsReader $facts,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssembleDailyRoute) {
            throw ExecutionException::because('AssembleDailyRouteHandler solo maneja AssembleDailyRoute');
        }

        $date = RouteDate::fromString($command->routeDate);

        // Una sola ruta ABIERTA por camión y día. Si la del día ya se completó, el camión puede volver a salir:
        // se arma una ruta NUEVA (otro id), que es la segunda salida del día.
        $existing = $this->routes->findByVehicleAndDate($command->vehicleId, $date);
        if ($existing !== null && $existing->status() !== RouteStatus::Completed) {
            throw ExecutionException::because("ya existe una ruta abierta para el vehículo {$command->vehicleId} el {$command->routeDate}");
        }

        // Contrato con la app: si el cliente NO manda paradas, el backend las ENSAMBLA desde el plan
        // publicado (planning). El cliente solo elige la fecha; el server arma la secuencia.
        $inputs = $command->stops !== []
            ? $command->stops
            : $this->plannedStops->forVehicleOnDate($command->vehicleId, $command->routeDate);

        if ($inputs === []) {
            throw ExecutionException::because("no hay un plan publicado para el vehículo {$command->vehicleId} el {$command->routeDate}; no se puede armar la ruta");
        }

        $stops = $this->openStops($inputs);
        if ($stops === []) {
            throw ExecutionException::because("todos los envíos del plan del vehículo {$command->vehicleId} para el {$command->routeDate} ya están entregados; no hay paradas que armar");
        }

        $route = VehicleRoute::assemble(
            $this->routes->nextIdentity(),
            $command->vehicleId,
            $date,
            $stops,
        );

        $this->routes->save($route);
        $this->lastRouteId = $route->id()->toString();

        $this->recordEvents->record($route->releaseEvents(), 'execution.route');
    }

    /**
     * Id de la ruta armada tras el último handle().
     */
    public function lastRouteId(): string
    {
        if ($this->lastRouteId === null) {
            throw ExecutionException::because('todavía no se armó ninguna ruta en este handler');
        }

        return $this->lastRouteId;
    }

    /**
     * Paradas de la ruta a partir de las candidatas, en su orden, SIN los envíos ya entregados (un envío
     * cerrado nunca es una parada de la ruta del día) y con la secuencia recompactada 1..n. Completa las
     * coordenadas desde el envío cuando la candidata no las trae.
     *
     * @param  list<RouteStopInput> $inputs
     * @return list<RouteStop>
     */
    private function openStops(array $inputs): array
    {
        usort($inputs, static fn (RouteStopInput $a, RouteStopInput $b): int => $a->sequence <=> $b->sequence);

        $shipmentIds = array_map(static fn (RouteStopInput $input): string => $input->shipmentId, $inputs);
        $closed = $this->facts->closedDays($shipmentIds);
        $points = $this->facts->coordinates($shipmentIds);

        $stops = [];
        foreach ($inputs as $input) {
            if (isset($closed[$input->shipmentId])) {
                continue;
            }

            $point = $points[$input->shipmentId] ?? null;
            $stops[] = RouteStop::plan(
                RouteStopId::generate(),
                $input->shipmentId,
                $closed === [] ? $input->sequence : count($stops) + 1,
                $input->orderReference,
                $input->customerName,
                $input->address,
                self::parseEta($input->etaAt),
                $input->lat ?? $point['lat'] ?? null,
                $input->lng ?? $point['lng'] ?? null,
            );
        }

        return $stops;
    }

    private static function parseEta(?string $etaAt): ?DateTimeImmutable
    {
        if ($etaAt === null || trim($etaAt) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($etaAt);
        } catch (Exception) {
            throw ExecutionException::because("ETA de parada inválida: {$etaAt}");
        }
    }
}
