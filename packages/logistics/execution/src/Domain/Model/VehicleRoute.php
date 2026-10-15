<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\DriverRouteAssembled;
use Logistics\Execution\Domain\Event\DriverRouteStopCompleted;
use Logistics\Execution\Domain\Event\DriverRouteStopFailed;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\IncidentSeverity;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz: la ruta del chofer para un camión y un día.
 *
 * Es la secuencia ordenada de TODAS las paradas (pedidos) que el chofer debe atender: deja una y procede
 * con la siguiente. Se arma a partir del plan publicado (contexto Planificación) y acompaña la ejecución
 * en terreno exponiendo, en todo momento, la "parada actual" (la primera pendiente) y las "restantes".
 *
 * No registra escaneos ni prueba de entrega: eso es del agregado Delivery. Esta ruta solo lleva el
 * AVANCE —qué parada se completó o falló— para guiar al chofer y alimentar al seguimiento (tracking) con
 * las paradas restantes. Cada parada que se cierra graba un evento con las paradas que faltan.
 *
 * Referencia a camión y envíos por id string: no se acopla a fleet, shipments ni planning.
 */
final class VehicleRoute extends AggregateRoot
{
    /**
     * @param list<RouteStop> $stops
     */
    private function __construct(
        private readonly RouteId $id,
        private readonly string $vehicleId,
        private readonly RouteDate $date,
        private RouteStatus $status,
        private array $stops,
    ) {}

    /**
     * Arma la ruta del día para un camión a partir de sus paradas (en el orden de reparto del plan). Las
     * paradas deben traer una secuencia 1..n sin huecos ni repetidos, y ningún envío dos veces.
     *
     * @param list<RouteStop> $stops
     */
    public static function assemble(RouteId $id, string $vehicleId, RouteDate $date, array $stops): self
    {
        $vehicle = trim($vehicleId);
        if ($vehicle === '') {
            throw ExecutionException::because('la ruta requiere el id del vehículo');
        }
        if ($stops === []) {
            throw ExecutionException::because('la ruta necesita al menos una parada');
        }

        $ordered = self::sortAndGuard($stops);

        $route = new self($id, $vehicle, $date, RouteStatus::Planned, $ordered);

        $route->recordEvent(new DriverRouteAssembled(
            $id->toString(),
            $vehicle,
            $date->toString(),
            count($ordered),
        ));

        return $route;
    }

    /**
     * Reconstituye la ruta desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<RouteStop> $stops
     */
    public static function reconstitute(
        RouteId $id,
        string $vehicleId,
        RouteDate $date,
        RouteStatus $status,
        array $stops,
    ): self {
        return new self($id, $vehicleId, $date, $status, self::bySequence($stops));
    }

    /**
     * Cierra con éxito la parada del envío dado (el chofer entregó) y avanza: la siguiente pendiente pasa
     * a ser la parada actual. Idempotente: si la parada ya estaba cerrada, no hace nada.
     *
     * Graba DriverRouteStopCompleted con las paradas que quedan, que es lo que consume el seguimiento.
     */
    public function completeStop(string $shipmentId, DateTimeImmutable $at): void
    {
        $stop = $this->requireStop($shipmentId);
        if (!$stop->isPending()) {
            return;
        }

        $stop->complete($at);
        $this->refreshStatus();

        $this->recordEvent(new DriverRouteStopCompleted(
            $this->id->toString(),
            $this->vehicleId,
            $shipmentId,
            $stop->sequence(),
            $this->stopsRemaining(),
            $at,
        ));
    }

    /**
     * Cierra como fallida la parada del envío dado (intento fallido) y avanza igual que completeStop:
     * la parada queda atendida y el chofer pasa a la siguiente. Idempotente si ya estaba cerrada.
     *
     * Graba DriverRouteStopFailed con el motivo y las paradas restantes.
     */
    public function failStop(string $shipmentId, string $reason, DateTimeImmutable $at): void
    {
        $stop = $this->requireStop($shipmentId);
        if (!$stop->isPending()) {
            return;
        }

        $stop->fail($at);
        $this->refreshStatus();

        $this->recordEvent(new DriverRouteStopFailed(
            $this->id->toString(),
            $this->vehicleId,
            $shipmentId,
            $stop->sequence(),
            $reason,
            $this->stopsRemaining(),
            $at,
        ));
    }

    /**
     * Reacción de la ruta ante un incidente del camión reportado por el chofer. Toca SOLO las paradas que
     * siguen PENDIENTES: las ya entregadas, fallidas o reprogramadas no se mueven. Devuelve, en orden, los
     * envíos de esas paradas pendientes (los afectados por el incidente).
     *
     * - MAJOR: la ruta pasa a SUSPENDIDA y cada parada pendiente se marca como REPROGRAMADA (sale de esta
     *   ruta; el envío se replanifica para otro día, aguas abajo).
     * - MINOR: la ruta NO cambia de estado; se empuja la ETA de cada parada pendiente por $delayMinutes
     *   (la parada sigue pendiente y el chofer la atiende, con retraso).
     *
     * No graba evento propio: el hecho inmutable del incidente lo registra el agregado VehicleIncident,
     * cuyo evento de dominio dispara la reacción en flota, planificación y notificaciones por el outbox.
     *
     * @return list<string> Envíos de las paradas pendientes al momento del incidente.
     */
    public function reportIncident(IncidentSeverity $severity, int $delayMinutes, DateTimeImmutable $at): array
    {
        $affected = [];

        foreach ($this->stops as $stop) {
            if (!$stop->isPending()) {
                continue;
            }

            $affected[] = $stop->shipmentId();

            if ($severity === IncidentSeverity::Major) {
                $stop->reschedule($at);
            } else {
                $stop->delayEtaBy($delayMinutes);
            }
        }

        if ($severity === IncidentSeverity::Major) {
            $this->status = RouteStatus::Suspended;
        }

        return $affected;
    }

    public function id(): RouteId
    {
        return $this->id;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function date(): RouteDate
    {
        return $this->date;
    }

    public function status(): RouteStatus
    {
        return $this->status;
    }

    /**
     * Paradas en el orden de reparto.
     *
     * @return list<RouteStop>
     */
    public function stops(): array
    {
        return $this->stops;
    }

    /**
     * Parada actual: la primera pendiente en la secuencia, o null si la ruta ya terminó.
     */
    public function currentStop(): ?RouteStop
    {
        foreach ($this->stops as $stop) {
            if ($stop->isPending()) {
                return $stop;
            }
        }

        return null;
    }

    /**
     * Paradas que faltan por atender, en orden.
     *
     * @return list<RouteStop>
     */
    public function remainingStops(): array
    {
        return array_values(array_filter($this->stops, static fn (RouteStop $stop): bool => $stop->isPending()));
    }

    /**
     * ¿Contiene una parada para este envío?
     */
    public function hasShipment(string $shipmentId): bool
    {
        foreach ($this->stops as $stop) {
            if ($stop->shipmentId() === $shipmentId) {
                return true;
            }
        }

        return false;
    }

    public function totalStops(): int
    {
        return count($this->stops);
    }

    public function stopsRemaining(): int
    {
        return count($this->remainingStops());
    }

    /**
     * Paradas ya atendidas (cerradas): entregadas o fallidas. Es el complemento de las restantes.
     */
    public function settledCount(): int
    {
        return $this->countBy(static fn (RouteStop $stop): bool => $stop->status()->isTerminal());
    }

    /**
     * Encuentra la parada del envío o falla: el envío debe pertenecer a esta ruta.
     */
    private function requireStop(string $shipmentId): RouteStop
    {
        foreach ($this->stops as $stop) {
            if ($stop->shipmentId() === $shipmentId) {
                return $stop;
            }
        }

        throw ExecutionException::because("el envío {$shipmentId} no pertenece a esta ruta");
    }

    /**
     * Recalcula el estado de la ruta tras cerrar una parada: Completed si ya no quedan pendientes,
     * InProgress si quedan pero alguna ya se cerró.
     */
    private function refreshStatus(): void
    {
        $this->status = $this->stopsRemaining() === 0 ? RouteStatus::Completed : RouteStatus::InProgress;
    }

    /**
     * @param callable(RouteStop):bool $predicate
     */
    private function countBy(callable $predicate): int
    {
        $count = 0;
        foreach ($this->stops as $stop) {
            if ($predicate($stop)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ordena las paradas por secuencia y valida que la secuencia sea 1..n sin huecos ni repetidos y que
     * ningún envío aparezca dos veces.
     *
     * @param  list<RouteStop> $stops
     * @return list<RouteStop>
     */
    private static function sortAndGuard(array $stops): array
    {
        $ordered = self::bySequence($stops);

        $seenShipments = [];
        $expected = 1;
        foreach ($ordered as $stop) {
            if ($stop->sequence() !== $expected) {
                throw ExecutionException::because('la secuencia de paradas debe ser 1..n sin huecos ni repetidos');
            }
            if (isset($seenShipments[$stop->shipmentId()])) {
                throw ExecutionException::because("el envío {$stop->shipmentId()} está dos veces en la ruta");
            }
            $seenShipments[$stop->shipmentId()] = true;
            $expected++;
        }

        return $ordered;
    }

    /**
     * @param  list<RouteStop> $stops
     * @return list<RouteStop>
     */
    private static function bySequence(array $stops): array
    {
        $ordered = array_values($stops);
        usort($ordered, static fn (RouteStop $a, RouteStop $b): int => $a->sequence() <=> $b->sequence());

        return $ordered;
    }
}
