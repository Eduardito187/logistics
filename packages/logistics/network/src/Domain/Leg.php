<?php

declare(strict_types=1);

namespace Logistics\Network\Domain;

use Logistics\Network\Domain\Event\LegDefined;
use Logistics\Network\Domain\Event\LegRemoved;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Tramo/corredor entre dos nodos de la red: la arista del grafo que une un nodo origen con un nodo
 * destino bajo uno de los seis corredores (ver CorridorType). Es la raíz de agregado de los tramos.
 *
 * El tramo es ruta lógica, no física: no conoce distancias ni tiempos ni camiones (eso es planning).
 * Solo declara que, dentro de la red, existe un corredor de un nodo a otro de cierto tipo.
 */
final class Leg extends AggregateRoot
{
    private function __construct(
        private readonly LegId $id,
        private readonly NodeId $from,
        private readonly NodeId $to,
        private readonly CorridorType $corridor,
    ) {}

    /**
     * Define un tramo nuevo entre dos nodos y graba el evento LegDefined.
     *
     * El origen y el destino deben ser nodos distintos: un corredor de un nodo a sí mismo no tiene
     * sentido en la red.
     */
    public static function define(
        LegId $id,
        NodeId $from,
        NodeId $to,
        CorridorType $corridor,
    ): self {
        if ($from->equals($to)) {
            throw NetworkException::because('un tramo no puede tener el mismo nodo de origen y destino');
        }

        $leg = new self($id, $from, $to, $corridor);
        $leg->recordEvent(new LegDefined(
            $id->toString(),
            $from->toString(),
            $to->toString(),
            $corridor->value,
        ));

        return $leg;
    }

    /**
     * Rehidrata un tramo desde su estado persistido. No graba eventos: reconstruye, no crea.
     */
    public static function fromState(
        LegId $id,
        NodeId $from,
        NodeId $to,
        CorridorType $corridor,
    ): self {
        return new self($id, $from, $to, $corridor);
    }

    /**
     * Marca el tramo como eliminado de la red y graba el evento LegRemoved. El borrado físico de la
     * fila lo hace el repositorio; el agregado solo registra el hecho para el outbox.
     */
    public function remove(): void
    {
        $this->recordEvent(new LegRemoved($this->id->toString()));
    }

    public function id(): LegId
    {
        return $this->id;
    }

    public function from(): NodeId
    {
        return $this->from;
    }

    public function to(): NodeId
    {
        return $this->to;
    }

    public function corridor(): CorridorType
    {
        return $this->corridor;
    }
}
