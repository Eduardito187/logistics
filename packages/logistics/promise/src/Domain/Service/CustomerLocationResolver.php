<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Service;

use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\ShipmentLocator;

/**
 * Servicio de dominio que resuelve el MUNICIPIO del cliente a partir de cualquiera de las tres formas en
 * que un canal identifica el destino: un municipio ya conocido, una coordenada (dirección geocodificada),
 * o un envío (del que se lee su coordenada). Es el paso común a la oferta de promesa y a la reserva, de
 * modo que ambas hablan el mismo eje (municipio) sin duplicar la lógica de resolución.
 *
 * Precedencia: municipio explícito > coordenada > envío. PHP puro: se apoya solo en los puertos del
 * dominio (MunicipalityResolver hacia coverage, ShipmentLocator hacia shipments). Tolerante: si no se
 * puede llegar a un municipio (punto fuera de cobertura, envío sin geocodificar o inexistente), devuelve
 * null y el caso de uso decide (la oferta responde vacío; la reserva exige municipio).
 */
final readonly class CustomerLocationResolver
{
    public function __construct(
        private MunicipalityResolver $municipalities,
        private ShipmentLocator $shipments,
    ) {}

    public function resolve(
        ?string $municipalityId,
        ?float $latitude,
        ?float $longitude,
        ?string $shipmentId,
    ): ?string {
        $explicit = $municipalityId !== null ? trim($municipalityId) : '';
        if ($explicit !== '') {
            return $explicit;
        }

        if ($latitude !== null && $longitude !== null) {
            return $this->municipalities->resolve($latitude, $longitude);
        }

        if ($shipmentId !== null && trim($shipmentId) !== '') {
            $point = $this->shipments->locate($shipmentId);
            if ($point !== null) {
                return $this->municipalities->resolve($point['latitude'], $point['longitude']);
            }
        }

        return null;
    }
}
