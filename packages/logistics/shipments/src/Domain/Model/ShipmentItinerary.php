<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;

/**
 * Itinerario de un envío: la secuencia ORDENADA de tramos que recorre (modelo hub-and-spoke).
 *
 * POR QUÉ UN AGREGADO APARTE (y no tramos dentro del agregado Shipment):
 * se eligió la forma MENOS invasiva. Meter una colección de tramos dentro de {@see Shipment} obligaría a
 * cambiar su constructor, `receive()`/`reconstitute()`, el `ShipmentMapper` y el `EloquentShipmentRepository`
 * (que ya sincroniza líneas y bultos) — mucha superficie, y el riesgo de romper el flujo de recepción
 * vigente. El itinerario es, además, un concepto que las olas de ruteo/ejecución van a hacer evolucionar
 * solo (estados de tramo, reruteo); mantenerlo como agregado propio lo deja extraíble a su propio servicio
 * sin arrastrar al envío. El vínculo con el envío es por `shipment_id` (misma identidad del Shipment), no
 * por FK de base: cada contexto/agregado queda autónomo (ADR 001/008).
 *
 * El envío conserva intactos su `origin_node_id` ("primer origen") y su `destination_node_id`
 * ("destino final"); el itinerario es el detalle del camino ENTRE esos dos extremos.
 *
 * CIMIENTO: solo ordena y expone sus tramos. Armar la red de tramos (ruteo) y decidir propio/tercerizado
 * son de olas posteriores; este agregado todavía no graba eventos de dominio.
 */
final class ShipmentItinerary extends AggregateRoot
{
    /**
     * @param list<ShipmentLeg> $legs
     */
    private function __construct(
        private readonly ShipmentId $shipmentId,
        private array $legs,
    ) {}

    /**
     * Arma un itinerario con sus tramos. Los ordena por `seq` y valida que no haya secuencias repetidas
     * (dos tramos en la misma posición es un error de construcción).
     *
     * @param list<ShipmentLeg> $legs
     */
    public static function fromLegs(ShipmentId $shipmentId, array $legs): self
    {
        return new self($shipmentId, self::sortedUnique($legs));
    }

    /**
     * Reconstituye el itinerario desde la persistencia, sin grabar eventos (no es un hecho nuevo).
     *
     * @param list<ShipmentLeg> $legs
     */
    public static function reconstitute(ShipmentId $shipmentId, array $legs): self
    {
        return new self($shipmentId, self::sortedUnique($legs));
    }

    public function shipmentId(): ShipmentId
    {
        return $this->shipmentId;
    }

    /**
     * Tramos del itinerario, ordenados por `seq`.
     *
     * @return list<ShipmentLeg>
     */
    public function legs(): array
    {
        return $this->legs;
    }

    public function isEmpty(): bool
    {
        return $this->legs === [];
    }

    public function count(): int
    {
        return count($this->legs);
    }

    /**
     * Ordena los tramos por `seq` y rechaza secuencias duplicadas.
     *
     * @param  list<ShipmentLeg> $legs
     * @return list<ShipmentLeg>
     */
    private static function sortedUnique(array $legs): array
    {
        $seqs = [];
        foreach ($legs as $leg) {
            if (isset($seqs[$leg->seq()])) {
                throw ShipmentsException::because("secuencia de tramo repetida: {$leg->seq()}");
            }
            $seqs[$leg->seq()] = true;
        }

        usort($legs, static fn (ShipmentLeg $a, ShipmentLeg $b): int => $a->seq() <=> $b->seq());

        return array_values($legs);
    }
}
