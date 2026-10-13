<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

/**
 * Envío candidato a entrar en el plan, con sus coordenadas y su foto legible (referencia del pedido,
 * cliente y dirección), tal como lo recibe el caso de uso. Esos datos los provee quien conoce el envío;
 * planificación no consulta el contexto de envíos. La referencia/cliente/dirección son opcionales.
 */
final readonly class ProposalStopInput
{
    public function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
    ) {}
}
