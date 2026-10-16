<?php

declare(strict_types=1);

namespace Logistics\Network\Domain;

use Logistics\Network\Domain\Event\ReceivingDockRegistered;
use Logistics\Network\Domain\Exception\NetworkException;
use Logistics\Network\Domain\ValueObject\DockCapacity;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Andén de recepción de un almacén central: por dónde entra la mercadería de proveedores (inbound/ASN).
 * Es la raíz de agregado del andén dentro del contexto network.
 *
 * El andén conoce su almacén (NodeId), su código humano, su nombre, su capacidad nominal (citas por
 * franja) y si está operativo. NO lleva la cuenta de cupos agendados por día: ese agendamiento vive en
 * el contexto `capacity` (eje inbound), que usa este dato maestro como tope de referencia.
 */
final class ReceivingDock extends AggregateRoot
{
    private function __construct(
        private readonly ReceivingDockId $id,
        private readonly NodeId $warehouseId,
        private readonly DockCode $code,
        private string $name,
        private DockCapacity $capacity,
        private bool $active,
    ) {}

    /**
     * Registra un andén nuevo. Nace activo y graba el evento ReceivingDockRegistered.
     */
    public static function register(
        ReceivingDockId $id,
        NodeId $warehouseId,
        DockCode $code,
        string $name,
        DockCapacity $capacity,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw NetworkException::because('el nombre del andén no puede estar vacío');
        }

        $dock = new self($id, $warehouseId, $code, $name, $capacity, true);
        $dock->recordEvent(new ReceivingDockRegistered(
            $id->toString(),
            $warehouseId->toString(),
            $code->toString(),
            $name,
            $capacity->appointmentsPerSlot,
        ));

        return $dock;
    }

    /**
     * Rehidrata un andén desde su estado persistido. No graba eventos: reconstruye, no crea.
     */
    public static function fromState(
        ReceivingDockId $id,
        NodeId $warehouseId,
        DockCode $code,
        string $name,
        DockCapacity $capacity,
        bool $active,
    ): self {
        return new self($id, $warehouseId, $code, $name, $capacity, $active);
    }

    public function activate(): void
    {
        $this->active = true;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function changeCapacity(DockCapacity $capacity): void
    {
        $this->capacity = $capacity;
    }

    public function id(): ReceivingDockId
    {
        return $this->id;
    }

    public function warehouseId(): NodeId
    {
        return $this->warehouseId;
    }

    public function code(): DockCode
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function capacity(): DockCapacity
    {
        return $this->capacity;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
