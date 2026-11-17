<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Service;

/**
 * Política que decide PROPIA vs. TERCERIZADO por DEPARTAMENTO DESTINO (configurable).
 *
 * Lee el mapa `logistics-shipments.outsourcing` (departamento destino → transportadora + almacén de
 * handoff). Un departamento que NO está en el mapa va por flota propia (`own_fleet`), que es el caso por
 * defecto: así, sin configurar nada, todo el país es flota propia y nada se tercieriza por accidente.
 *
 * El departamento de ORIGEN se recibe por si en el futuro la decisión depende del par origen→destino; hoy
 * la regla solo mira el destino. La config fina estilo Magento (/admin/configuration) es un follow-up.
 */
final readonly class OutsourcingDecision
{
    /**
     * @param array<string, array{carrier_id: string, carrier_hub_node_id: string|null}> $byDestinationDepartment
     *        mapa departamento destino (normalizado) → datos de la transportadora
     */
    public function __construct(
        private array $byDestinationDepartment,
    ) {}

    /**
     * Decide el modo de acarreo del envío según el departamento destino. Tolerante: un destino nulo/vacío o
     * no mapeado cae a flota propia.
     */
    public function decide(?string $destinationDepartment, ?string $originDepartment = null): OutsourcingChoice
    {
        $key = self::normalize($destinationDepartment);
        if ($key === null || !isset($this->byDestinationDepartment[$key])) {
            return OutsourcingChoice::ownFleet();
        }

        $entry = $this->byDestinationDepartment[$key];

        return OutsourcingChoice::outsourced($entry['carrier_id'], $entry['carrier_hub_node_id']);
    }

    /** Normaliza un código de departamento (minúsculas, sin espacios al borde), o null si viene vacío. */
    private static function normalize(?string $value): ?string
    {
        $trimmed = $value !== null ? strtolower(trim($value)) : '';

        return $trimmed === '' ? null : $trimmed;
    }
}
