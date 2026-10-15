<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Model;

use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\LoadLane;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\StopSequence;

/**
 * Parada del plan: la entrega de un envío en una posición de la secuencia de reparto.
 *
 * Entidad del agregado DeliveryPlan: tiene identidad propia pero solo vive dentro del plan que la
 * contiene. Referencia al envío SOLO por id string (no se acopla al contexto de envíos). La secuencia,
 * la ETA y el carril los fija el agregado al proponer o recomputar; por eso son mutables y, hasta la
 * propuesta, ETA y carril pueden estar vacíos.
 *
 * Además de la referencia al envío, la parada guarda una FOTO legible del destino —referencia del
 * pedido, cliente y dirección— que aporta quien conoce el envío al asignarlo. Es un dato denormalizado
 * a propósito: deja que el plan (y la ruta del chofer que se arma a partir de él) se muestren y se
 * entreguen sin volver a consultar el contexto de envíos. Son opcionales: una parada mínima solo trae
 * el id del envío.
 */
final class PlannedStop
{
    private function __construct(
        private readonly PlannedStopId $id,
        private readonly string $shipmentId,
        private StopSequence $sequence,
        private ?Eta $eta,
        private ?LoadLane $lane,
        private readonly ?string $orderReference,
        private readonly ?string $customerName,
        private readonly ?string $address,
    ) {}

    /**
     * Crea una parada recién asignada: con posición y la foto legible del destino, todavía sin ETA ni
     * carril. La referencia del pedido, el cliente y la dirección las aporta quien conoce el envío.
     */
    public static function assign(
        PlannedStopId $id,
        string $shipmentId,
        StopSequence $sequence,
        ?string $orderReference = null,
        ?string $customerName = null,
        ?string $address = null,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $sequence,
            null,
            null,
            self::clean($orderReference),
            self::clean($customerName),
            self::clean($address),
        );
    }

    /**
     * Reconstituye una parada desde la persistencia, con todos sus datos ya resueltos (o vacíos).
     */
    public static function reconstitute(
        PlannedStopId $id,
        string $shipmentId,
        StopSequence $sequence,
        ?Eta $eta,
        ?LoadLane $lane,
        ?string $orderReference = null,
        ?string $customerName = null,
        ?string $address = null,
    ): self {
        return new self($id, $shipmentId, $sequence, $eta, $lane, $orderReference, $customerName, $address);
    }

    /**
     * Fija la posición de la parada en la secuencia de reparto. Solo lo llama el agregado.
     */
    public function placeAt(StopSequence $sequence): void
    {
        $this->sequence = $sequence;
    }

    /**
     * Fija la ETA calculada por el motor de rutas. Solo lo llama el agregado.
     */
    public function arriveAt(Eta $eta): void
    {
        $this->eta = $eta;
    }

    /**
     * Asigna el carril de carga. Solo lo llama el agregado al recomputar el plan de carga.
     */
    public function loadInto(LoadLane $lane): void
    {
        $this->lane = $lane;
    }

    public function id(): PlannedStopId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function sequence(): StopSequence
    {
        return $this->sequence;
    }

    public function eta(): ?Eta
    {
        return $this->eta;
    }

    public function lane(): ?LoadLane
    {
        return $this->lane;
    }

    /**
     * Referencia legible del pedido (p. ej. el id externo del envío), o null si no se aportó.
     */
    public function orderReference(): ?string
    {
        return $this->orderReference;
    }

    /**
     * Nombre del cliente que recibe en esta parada, o null si no se aportó.
     */
    public function customerName(): ?string
    {
        return $this->customerName;
    }

    /**
     * Dirección de entrega de esta parada, o null si no se aportó.
     */
    public function address(): ?string
    {
        return $this->address;
    }

    /**
     * Normaliza un texto opcional: recorta espacios y convierte el vacío en null.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
