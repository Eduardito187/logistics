<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

/**
 * Envío candidato a entrar en el plan, con sus coordenadas y su foto legible (referencia del pedido,
 * cliente y dirección), tal como lo recibe el caso de uso. Salvo el id, cada dato es OPCIONAL: lo que no viene
 * lo completa el caso de uso con los datos del envío (lectura en lote por contrato). Un envío sin
 * coordenadas acá y sin ubicación propia no se puede planificar.
 */
final readonly class ProposalStopInput
{
    public function __construct(
        public string $shipmentId,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
    ) {}
}
