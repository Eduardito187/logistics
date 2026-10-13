<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssembleDailyRoute;

use DateTimeImmutable;
use Exception;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
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
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AssembleDailyRoute) {
            throw ExecutionException::because('AssembleDailyRouteHandler solo maneja AssembleDailyRoute');
        }

        $date = RouteDate::fromString($command->routeDate);

        if ($this->routes->findByVehicleAndDate($command->vehicleId, $date) !== null) {
            throw ExecutionException::because("ya existe una ruta para el vehículo {$command->vehicleId} el {$command->routeDate}");
        }

        $stops = array_map(
            static fn (RouteStopInput $input): RouteStop => RouteStop::plan(
                RouteStopId::generate(),
                $input->shipmentId,
                $input->sequence,
                $input->orderReference,
                $input->customerName,
                $input->address,
                self::parseEta($input->etaAt),
                $input->lat,
                $input->lng,
            ),
            $command->stops,
        );

        $route = VehicleRoute::assemble(
            $this->routes->nextIdentity(),
            $command->vehicleId,
            $date,
            array_values($stops),
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
