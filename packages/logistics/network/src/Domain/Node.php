<?php

declare(strict_types=1);

namespace Logistics\Network\Domain;

use Logistics\Network\Domain\Event\NodeDeactivated;
use Logistics\Network\Domain\Event\NodeRegistered;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Nodo de la red logística: un CD, una tienda o un punto de recojo, con su código legible, su
 * ubicación y su estado. Es la raíz de agregado del contexto network.
 *
 * El nodo no conoce rutas ni camiones: solo su identidad, dónde está y si está operativo.
 */
final class Node extends AggregateRoot
{
    private function __construct(
        private readonly NodeId $id,
        private readonly NodeCode $code,
        private string $name,
        private readonly NodeType $type,
        private GeoPoint $location,
        private NodeStatus $status,
    ) {}

    /**
     * Registra un nodo nuevo. Nace activo y graba el evento NodeRegistered.
     */
    public static function register(
        NodeId $id,
        NodeCode $code,
        string $name,
        NodeType $type,
        GeoPoint $location,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw NetworkException::because('el nombre del nodo no puede estar vacío');
        }

        $node = new self($id, $code, $name, $type, $location, NodeStatus::Active);
        $node->recordEvent(new NodeRegistered(
            $id->toString(),
            $code->toString(),
            $name,
            $type->value,
            $location->latitude,
            $location->longitude,
        ));

        return $node;
    }

    /**
     * Rehidrata un nodo desde su estado persistido. No graba eventos: reconstruye, no crea.
     */
    public static function fromState(
        NodeId $id,
        NodeCode $code,
        string $name,
        NodeType $type,
        GeoPoint $location,
        NodeStatus $status,
    ): self {
        return new self($id, $code, $name, $type, $location, $status);
    }

    public function activate(): void
    {
        $this->status = NodeStatus::Active;
    }

    public function deactivate(): void
    {
        if ($this->status === NodeStatus::Inactive) {
            return;
        }

        $this->status = NodeStatus::Inactive;
        $this->recordEvent(new NodeDeactivated($this->id->toString(), $this->code->toString()));
    }

    public function relocate(GeoPoint $location): void
    {
        $this->location = $location;
    }

    public function id(): NodeId
    {
        return $this->id;
    }

    public function code(): NodeCode
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): NodeType
    {
        return $this->type;
    }

    public function location(): GeoPoint
    {
        return $this->location;
    }

    public function status(): NodeStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return $this->status === NodeStatus::Active;
    }
}
