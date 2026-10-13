<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\GeneratePlanProposal;

/**
 * Envío candidato a entrar en el plan, con sus coordenadas, tal como lo recibe el caso de uso. Las
 * coordenadas las provee quien conoce el envío; planificación no consulta el contexto de envíos.
 */
final readonly class ProposalStopInput
{
    public function __construct(
        public string $shipmentId,
        public float $latitude,
        public float $longitude,
    ) {}
}
