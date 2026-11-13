<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Outbox;

use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Reactor outbox (Fase 3.3): cuando PLANIFICACIÓN publica un plan (`planning.plan_published`), EJECUCIÓN
 * arma sola la ruta del día del chofer para ese camión. Es el primer eslabón de la cadena automática
 * publicación → ruta → salida → trip: hasta ahora la ruta de ejecución había que armarla a mano.
 *
 * Despacha AssembleDailyRoute SIN paradas: el handler las toma del plan publicado (PlannedRouteStopsProvider),
 * así la secuencia y las ETA salen de planificación sin duplicar datos en el evento.
 *
 * Idempotente: el outbox reentrega at-least-once, y AssembleDailyRoute lanza si ya existe una ruta para el
 * camión y el día. Por eso ANTES de despachar se comprueba la existencia y, si ya está armada, no se hace
 * nada (la segunda entrega del mismo hecho no vuelve a intentar armarla ni ensucia el dead-letter).
 */
final readonly class AssembleRouteOnPlanPublished implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private VehicleRouteRepository $routes,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'planning.plan_published') {
            return;
        }

        $payload = $message->payload();

        $vehicleId = $payload['vehicle_id'] ?? null;
        $planDate = $payload['plan_date'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '' || !is_string($planDate) || $planDate === '') {
            return;
        }

        $date = RouteDate::fromString($planDate);

        // Idempotencia: una ruta por camión y día. Si ya está armada (reentrega o armada a mano), no-op.
        if ($this->routes->findByVehicleAndDate($vehicleId, $date) !== null) {
            return;
        }

        try {
            $this->commandBus->dispatch(new AssembleDailyRoute(
                vehicleId: $vehicleId,
                routeDate: $planDate,
                stops: [],
            ));
        } catch (ExecutionException $exception) {
            // Carrera: otra entrega del mismo hecho pudo armar la ruta entre la comprobación y el despacho.
            // Si ahora existe, el hecho ya está cumplido y se traga (no va a dead-letter); si no, es un fallo
            // real (p. ej. el plan publicado no trajo paradas) y se re-lanza para que el relay reintente.
            if ($this->routes->findByVehicleAndDate($vehicleId, $date) === null) {
                throw $exception;
            }
        }
    }
}
