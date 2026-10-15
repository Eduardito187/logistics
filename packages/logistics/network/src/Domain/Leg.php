<?php

declare(strict_types=1);

namespace Logistics\Network\Domain;

use Logistics\Network\Domain\Event\LegDefined;
use Logistics\Network\Domain\Event\LegRemoved;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeType;
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
     * Valida los extremos contra el corredor: el origen y el destino deben ser nodos distintos, el
     * corredor debe unir dos nodos (los corredores terminales —CdToCustomer, StorePickup— acaban en
     * el cliente y no son tramos) y los tipos de nodo de origen y destino deben calzar con los que
     * declara el corredor (p. ej. CdToStore exige un CD de origen y una tienda de destino).
     *
     * Recibe los tipos de nodo como argumentos de validación: el tramo no los almacena (su estado es
     * solo id, origen, destino y corredor), pero los necesita para hacer cumplir la invariante en el
     * mismo lugar donde nace el agregado.
     */
    public static function define(
        LegId $id,
        NodeId $from,
        NodeType $fromType,
        NodeId $to,
        NodeType $toType,
        CorridorType $corridor,
    ): self {
        if ($from->equals($to)) {
            throw NetworkException::because('un tramo no puede tener el mismo nodo de origen y destino');
        }

        if ($corridor->isTerminal()) {
            throw NetworkException::because(
                "el corredor {$corridor->value} acaba en el cliente, no en un nodo: no puede ser un tramo",
            );
        }

        if (!$corridor->acceptsEndpoints($fromType, $toType)) {
            throw NetworkException::because(
                "los tipos de nodo ({$fromType->value} → {$toType->value}) no corresponden al corredor {$corridor->value}",
            );
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
