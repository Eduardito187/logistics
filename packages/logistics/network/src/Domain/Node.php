<?php

declare(strict_types=1);

namespace Logistics\Network\Domain;

use Logistics\Network\Domain\Event\NodeDeactivated;
use Logistics\Network\Domain\Event\NodeRegistered;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\Department;
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
        private ?Department $department,
    ) {}

    /**
     * Registra un nodo nuevo. Nace activo y graba el evento NodeRegistered.
     *
     * El departamento es opcional: un nodo importado de SAP puede llegar sin él y asignarse después
     * (assignDepartment). Mientras no lo tenga, no participa de las consultas de corredores por
     * departamento.
     */
    public static function register(
        NodeId $id,
        NodeCode $code,
        string $name,
        NodeType $type,
        GeoPoint $location,
        ?Department $department = null,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw NetworkException::because('el nombre del nodo no puede estar vacío');
        }

        $node = new self($id, $code, $name, $type, $location, NodeStatus::Active, $department);
        $node->recordEvent(new NodeRegistered(
            $id->toString(),
            $code->toString(),
            $name,
            $type->value,
            $location->latitude,
            $location->longitude,
            $department?->value,
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
        ?Department $department = null,
    ): self {
        return new self($id, $code, $name, $type, $location, $status, $department);
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

    /**
     * Asigna (o reasigna) el departamento del nodo. No graba evento: es un dato maestro que se
     * completa o corrige, no un hecho de negocio con suscriptores aguas abajo.
     */
    public function assignDepartment(?Department $department): void
    {
        $this->department = $department;
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

    public function department(): ?Department
    {
        return $this->department;
    }

    public function isActive(): bool
    {
        return $this->status === NodeStatus::Active;
    }
}
