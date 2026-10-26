<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Model;

use Logistics\Planning\Domain\Event\PlanProposed;
use Logistics\Planning\Domain\Event\PlanPublished;
use Logistics\Planning\Domain\Event\RouteReoptimized;
use Logistics\Planning\Domain\Event\StopRemovedFromPlan;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\LoadLane;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;
use Logistics\Planning\Domain\ValueObject\StopSequence;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Agregado raíz del contexto: el plan de reparto de un camión para un día.
 *
 * Modela la propuesta pedido→camión: se le asignan envíos (Draft), el motor de rutas resuelve orden y
 * ETA (Proposed), un humano lo ajusta y finalmente lo publica (Published) para que la ejecución lo tome.
 * Referencia al vehículo y a los envíos SOLO por id string: no importa ni conoce otros contextos.
 *
 * NO ejecuta la ruta ni registra escaneos: su responsabilidad termina al publicar.
 */
final class DeliveryPlan extends AggregateRoot
{
    /** Cantidad de bultos por carril de carga: define el tamaño de cada zona física del camión. */
    private const LANE_SIZE = 6;

    /**
     * @param list<PlannedStop> $stops
     */
    private function __construct(
        private readonly PlanId $id,
        private readonly PlanDate $date,
        private readonly string $vehicleId,
        private PlanStatus $status,
        private array $stops,
        private ?RouteGeometry $geometry = null,
    ) {}

    /**
     * Abre un plan nuevo en borrador para un camión y un día, todavía sin paradas.
     */
    public static function startDraft(PlanId $id, PlanDate $date, string $vehicleId): self
    {
        $trimmedVehicle = trim($vehicleId);
        if ($trimmedVehicle === '') {
            throw PlanningException::because('el id de vehículo no puede estar vacío');
        }

        return new self($id, $date, $trimmedVehicle, PlanStatus::Draft, []);
    }

    /**
     * Reconstituye el plan desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<PlannedStop> $stops
     */
    public static function reconstitute(
        PlanId $id,
        PlanDate $date,
        string $vehicleId,
        PlanStatus $status,
        array $stops,
        ?RouteGeometry $geometry = null,
    ): self {
        return new self($id, $date, $vehicleId, $status, array_values($stops), $geometry);
    }

    /**
     * Agrega un envío al final de la secuencia del plan. Solo mientras no esté publicado.
     *
     * No admite el mismo envío dos veces en el mismo plan. La parada nace sin ETA ni carril: eso lo
     * resuelven la propuesta de ruta y el recálculo del plan de carga. La referencia del pedido, el
     * cliente y la dirección (foto legible del destino) son opcionales y las aporta quien conoce el
     * envío; viajan con la parada para que el plan y la ruta del chofer se muestren sin consultar envíos.
     */
    public function assignShipment(
        PlannedStopId $stopId,
        string $shipmentId,
        ?string $orderReference = null,
        ?string $customerName = null,
        ?string $address = null,
    ): void {
        $this->guardNotPublished('asignar envíos');

        $trimmed = trim($shipmentId);
        if ($trimmed === '') {
            throw PlanningException::because('el id de envío no puede estar vacío');
        }
        if ($this->hasShipment($trimmed)) {
            throw PlanningException::because("el envío {$trimmed} ya está en el plan");
        }

        $this->stops[] = PlannedStop::assign(
            $stopId,
            $trimmed,
            StopSequence::of(count($this->stops) + 1),
            $orderReference,
            $customerName,
            $address,
        );
    }

    /**
     * Aplica el resultado del motor de rutas: ordena las paradas y les fija la ETA calculada, pasando el
     * plan de borrador a propuesto. Es el paso que concreta la propuesta automática.
     *
     * @param list<string>       $orderedShipmentIds envíos en el orden de reparto que devolvió el motor
     * @param array<string, Eta> $etas               ETA por id de envío
     */
    public function proposeRoute(array $orderedShipmentIds, array $etas): void
    {
        if ($this->status !== PlanStatus::Draft) {
            throw PlanningException::because("solo se puede proponer la ruta de un plan en borrador (estado actual: {$this->status->value})");
        }
        if ($this->stops === []) {
            throw PlanningException::because('no se puede proponer la ruta de un plan sin paradas');
        }

        $this->applyOrder($orderedShipmentIds);

        foreach ($this->stops as $stop) {
            $shipmentId = $stop->shipmentId();
            if (!isset($etas[$shipmentId])) {
                throw PlanningException::because("falta la ETA del envío {$shipmentId} en la propuesta");
            }
            $stop->arriveAt($etas[$shipmentId]);
        }

        $this->status = PlanStatus::Proposed;

        $this->recordEvent(new PlanProposed(
            $this->id->toString(),
            $this->date->toString(),
            $this->vehicleId,
            count($this->stops),
        ));
    }

    /**
     * RE-OPTIMIZACIÓN incremental (R-05): aplica un nuevo orden de reparto y una nueva ETA calculados por
     * el re-secuenciador ante un cambio del día (nuevo pedido, cancelación, incidente), sin rehacer el
     * resto del día. A diferencia de reorderStops (ajuste humano que conserva la ETA), acá se recalcula la
     * ETA de cada parada. Invalida la geometría guardada (el orden cambió) y graba RouteReoptimized.
     *
     * No se puede sobre un plan publicado (su hoja de ruta ya está congelada). El orden debe ser
     * exactamente el conjunto de envíos del plan; debe traer la ETA de cada uno.
     *
     * @param list<string>       $orderedShipmentIds envíos en el nuevo orden de reparto
     * @param array<string, Eta> $etas               ETA por id de envío
     */
    public function reoptimizeTo(array $orderedShipmentIds, array $etas, string $reason): void
    {
        $this->guardNotPublished('re-optimizar la ruta');

        if ($this->stops === []) {
            throw PlanningException::because('no se puede re-optimizar un plan sin paradas');
        }

        $this->applyOrder($orderedShipmentIds);

        foreach ($this->stops as $stop) {
            $shipmentId = $stop->shipmentId();
            if (!isset($etas[$shipmentId])) {
                throw PlanningException::because("falta la ETA del envío {$shipmentId} en la re-optimización");
            }
            $stop->arriveAt($etas[$shipmentId]);
        }

        $this->geometry = null;

        $this->recordEvent(new RouteReoptimized(
            $this->id->toString(),
            $this->date->toString(),
            $this->vehicleId,
            count($this->stops),
            trim($reason) === '' ? 'manual' : trim($reason),
        ));
    }

    /**
     * Ajuste humano: reordena las paradas al orden dado y renumera la secuencia. Cada parada conserva su
     * ETA y su carril; recomputar el plan de carga después refleja el nuevo orden. No se puede sobre un
     * plan publicado.
     *
     * Invalida la geometría guardada: al cambiar el orden, la polilínea por calles deja de corresponder y
     * debe recalcularse en el servidor antes de volver a mostrarla.
     *
     * @param list<string> $orderedShipmentIds todos los envíos del plan, en el nuevo orden de reparto
     */
    public function reorderStops(array $orderedShipmentIds): void
    {
        $this->guardNotPublished('reordenar paradas');

        $this->applyOrder($orderedShipmentIds);
        $this->geometry = null;
    }

    /**
     * Quita del plan la parada del envío dado y renumera la secuencia 1..n. Es la contraparte de
     * assignShipment: habilita MOVER un envío a otro camión (el plan de origen lo suelta, el de destino lo
     * toma). No se puede sobre un plan publicado (su hoja de ruta ya está congelada).
     *
     * Invalida la geometría guardada: al cambiar el conjunto de paradas, la polilínea por calles deja de
     * corresponder y debe recalcularse en el servidor antes de volver a mostrarla. Graba el evento
     * StopRemovedFromPlan para que el outbox propague el cambio.
     */
    public function removeStop(string $shipmentId): void
    {
        $this->guardNotPublished('quitar envíos');

        $trimmed = trim($shipmentId);
        if ($trimmed === '') {
            throw PlanningException::because('el id de envío no puede estar vacío');
        }
        if (!$this->hasShipment($trimmed)) {
            throw PlanningException::because("el envío {$trimmed} no está en el plan");
        }

        $remaining = [];
        foreach ($this->stops as $stop) {
            if ($stop->shipmentId() !== $trimmed) {
                $remaining[] = $stop;
            }
        }

        // Renumera la secuencia 1..n tras quitar la parada, para que el orden quede sin huecos.
        $sequence = StopSequence::first();
        foreach ($remaining as $stop) {
            $stop->placeAt($sequence);
            $sequence = $sequence->next();
        }

        $this->stops = $remaining;
        $this->geometry = null;

        $this->recordEvent(new StopRemovedFromPlan(
            $this->id->toString(),
            $this->date->toString(),
            $this->vehicleId,
            $trimmed,
            count($this->stops),
        ));
    }

    /**
     * Fija la geometría de la ruta por calles calculada en el SERVIDOR (polilínea GeoJSON + distancia y
     * duración totales), para persistirla con el plan y que el mapa la pinte sin depender del navegador. No
     * se puede sobre un plan publicado (su hoja de ruta ya está congelada).
     */
    public function attachRouteGeometry(RouteGeometry $geometry): void
    {
        $this->guardNotPublished('adjuntar la geometría de la ruta');

        $this->geometry = $geometry;
    }

    /**
     * Completa la geometría de un plan que quedó SIN ella (p. ej. publicado antes de que se calculara).
     * Es un dato derivado para el mapa: no cambia paradas, orden ni ETA, por eso se admite en cualquier
     * estado, incluso publicado. Si el plan ya tiene geometría no hace nada (no se reescribe una hoja de
     * ruta congelada). Devuelve true si la adjuntó.
     */
    public function fillMissingRouteGeometry(RouteGeometry $geometry): bool
    {
        if ($this->geometry !== null) {
            return false;
        }

        $this->geometry = $geometry;

        return true;
    }

    /**
     * Recalcula el plan de carga: asigna a cada parada su carril en ORDEN INVERSO de entrega (el último
     * en entregarse se carga primero, al fondo; el primero en entregarse queda más a mano, cerca de la
     * puerta). Los carriles se llenan en bloques de LANE_SIZE bultos. No se puede sobre un plan publicado.
     */
    public function recomputeLoadPlan(): void
    {
        $this->guardNotPublished('recalcular el plan de carga');

        $total = count($this->stops);
        foreach ($this->stops as $stop) {
            // Orden de carga = inverso del orden de entrega: la última entrega (sequence alto) carga primero.
            $loadOrder = $total - $stop->sequence()->value + 1;
            $laneIndex = intdiv($loadOrder - 1, self::LANE_SIZE);
            $stop->loadInto(LoadLane::of(chr(ord('A') + $laneIndex)));
        }
    }

    /**
     * Publica el plan: lo confirma y lo deja disponible para la ejecución. Solo desde Proposed.
     */
    public function publish(): void
    {
        if ($this->status !== PlanStatus::Proposed) {
            throw PlanningException::because("solo se puede publicar un plan propuesto (estado actual: {$this->status->value})");
        }

        $this->status = PlanStatus::Published;

        $this->recordEvent(new PlanPublished(
            $this->id->toString(),
            $this->date->toString(),
            $this->vehicleId,
            count($this->stops),
        ));
    }

    public function id(): PlanId
    {
        return $this->id;
    }

    public function date(): PlanDate
    {
        return $this->date;
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }

    public function status(): PlanStatus
    {
        return $this->status;
    }

    /**
     * Geometría de la ruta por calles ya calculada, o null si todavía no se adjuntó.
     */
    public function geometry(): ?RouteGeometry
    {
        return $this->geometry;
    }

    /**
     * Paradas en el orden de reparto actual.
     *
     * @return list<PlannedStop>
     */
    public function stops(): array
    {
        return $this->stops;
    }

    /**
     * Reordena las paradas según la lista de envíos dada y renumera la secuencia 1..n. La lista debe ser
     * exactamente el conjunto de envíos del plan, sin faltantes ni sobrantes ni repetidos.
     *
     * @param list<string> $orderedShipmentIds
     */
    private function applyOrder(array $orderedShipmentIds): void
    {
        if (count($orderedShipmentIds) !== count($this->stops)) {
            throw PlanningException::because('el orden debe incluir todas las paradas del plan, exactamente una vez');
        }

        $byShipment = [];
        foreach ($this->stops as $stop) {
            $byShipment[$stop->shipmentId()] = $stop;
        }

        $reordered = [];
        $sequence = StopSequence::first();
        foreach ($orderedShipmentIds as $shipmentId) {
            if (!isset($byShipment[$shipmentId])) {
                throw PlanningException::because("el orden referencia un envío que no está en el plan: {$shipmentId}");
            }
            $stop = $byShipment[$shipmentId];
            unset($byShipment[$shipmentId]);

            $stop->placeAt($sequence);
            $reordered[] = $stop;
            $sequence = $sequence->next();
        }

        $this->stops = $reordered;
    }

    private function hasShipment(string $shipmentId): bool
    {
        foreach ($this->stops as $stop) {
            if ($stop->shipmentId() === $shipmentId) {
                return true;
            }
        }

        return false;
    }

    private function guardNotPublished(string $action): void
    {
        if ($this->status === PlanStatus::Published) {
            throw PlanningException::because("no se puede {$action} en un plan ya publicado");
        }
    }
}
