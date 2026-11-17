<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Service;

use Logistics\Network\Application\Contract\ItineraryPlanner;
use Logistics\Network\Application\Contract\NetworkNodeDirectory;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\HaulageMode;

/**
 * Ruteo por defecto: aplica el modelo Dismac de tramos sobre el directorio de nodos de la red.
 *
 * Determinista dado el directorio: resuelve departamentos y hubs con el lector y arma la secuencia con
 * reglas puras. Tolerante a datos faltantes (ver cada rama): si no puede resolver un hub intermedio,
 * degrada a un tramo directo en vez de romper, para no bloquear a planning.
 */
final readonly class RoutedItineraryPlanner implements ItineraryPlanner
{
    public function __construct(
        private NetworkNodeDirectory $directory,
    ) {}

    public function planLegs(
        string $originNodeId,
        ?string $destinationNodeId,
        string $destinationDepartment,
        ?array $customerGeo,
        string $haulageMode,
        ?string $carrierId,
        ?string $carrierHubNodeId,
    ): array {
        $originDepartment = $this->directory->departmentOf($originNodeId);
        $destinationDepartmentCode = Department::tryFromText($destinationDepartment)?->value;

        // Entrega a domicilio: el último tramo termina en el cliente (to_node_id null); entrega a un nodo
        // de la red: termina en ese nodo. El cliente por geo (customerGeo) implica destinationNodeId null.
        $finalNodeId = $destinationNodeId;

        $mode = HaulageMode::tryFromLoose($haulageMode);

        if ($mode === HaulageMode::Outsourced) {
            return $this->outsourcedLegs(
                $originNodeId,
                $finalNodeId,
                $originDepartment,
                $destinationDepartmentCode,
                $carrierId,
                $carrierHubNodeId,
            );
        }

        // Flota propia (own_fleet por defecto si el modo no se reconoce).
        return $this->ownFleetLegs(
            $originNodeId,
            $finalNodeId,
            $originDepartment,
            $destinationDepartmentCode,
        );
    }

    /**
     * Flota propia. Mismo departamento (o departamento indeterminable): un solo tramo directo.
     * Interdepartamental: dos tramos, el primero hasta el hub del departamento destino.
     *
     * @return list<array{seq: int, from_node_id: string, to_node_id: string|null, from_department: string|null, to_department: string|null, haulage_mode: string, carrier_id: string|null}>
     */
    private function ownFleetLegs(
        string $originNodeId,
        ?string $finalNodeId,
        ?string $originDepartment,
        ?string $destinationDepartment,
    ): array {
        $sameDepartment = $originDepartment !== null
            && $destinationDepartment !== null
            && $originDepartment === $destinationDepartment;

        // Mismo departamento, o no se puede determinar el destino: un tramo directo origen → destino.
        if ($sameDepartment || $destinationDepartment === null) {
            return [
                $this->leg(1, $originNodeId, $finalNodeId, $originDepartment, $destinationDepartment, HaulageMode::LEG_OWN_FLEET, null),
            ];
        }

        // Interdepartamental: primer tramo hasta el hub del departamento destino.
        $destinationHub = $this->directory->hubOfDepartment($destinationDepartment);

        // Sin hub sembrado en el departamento destino: degrada a un tramo directo (riesgo documentado).
        if ($destinationHub === null) {
            return [
                $this->leg(1, $originNodeId, $finalNodeId, $originDepartment, $destinationDepartment, HaulageMode::LEG_OWN_FLEET, null),
            ];
        }

        return [
            $this->leg(1, $originNodeId, $destinationHub, $originDepartment, $destinationDepartment, HaulageMode::LEG_OWN_FLEET, null),
            $this->leg(2, $destinationHub, $finalNodeId, $destinationDepartment, $destinationDepartment, HaulageMode::LEG_OWN_FLEET, null),
        ];
    }

    /**
     * Tercerizado: handoff corto de flota propia al almacén de la transportadora y luego tramo third_party
     * hasta el destino. Sin almacén de transportadora sembrado: degrada a un tramo único third_party
     * directo (riesgo documentado).
     *
     * @return list<array{seq: int, from_node_id: string, to_node_id: string|null, from_department: string|null, to_department: string|null, haulage_mode: string, carrier_id: string|null}>
     */
    private function outsourcedLegs(
        string $originNodeId,
        ?string $finalNodeId,
        ?string $originDepartment,
        ?string $destinationDepartment,
        ?string $carrierId,
        ?string $carrierHubNodeId,
    ): array {
        $carrierHub = $carrierHubNodeId ?? $this->directory->carrierHub($carrierId);

        // Sin almacén de transportadora: un solo tramo third_party directo origen → destino.
        if ($carrierHub === null) {
            return [
                $this->leg(1, $originNodeId, $finalNodeId, $originDepartment, $destinationDepartment, HaulageMode::LEG_THIRD_PARTY, $carrierId),
            ];
        }

        $carrierHubDepartment = $this->directory->departmentOf($carrierHub);

        return [
            // Handoff nuestro: flota propia hasta el almacén de la transportadora.
            $this->leg(1, $originNodeId, $carrierHub, $originDepartment, $carrierHubDepartment, HaulageMode::LEG_OWN_FLEET, null),
            // Tramo largo del tercero hasta el destino final.
            $this->leg(2, $carrierHub, $finalNodeId, $carrierHubDepartment, $destinationDepartment, HaulageMode::LEG_THIRD_PARTY, $carrierId),
        ];
    }

    /**
     * Arma un tramo en la forma primitiva del contrato.
     *
     * @return array{seq: int, from_node_id: string, to_node_id: string|null, from_department: string|null, to_department: string|null, haulage_mode: string, carrier_id: string|null}
     */
    private function leg(
        int $seq,
        string $fromNodeId,
        ?string $toNodeId,
        ?string $fromDepartment,
        ?string $toDepartment,
        string $haulageMode,
        ?string $carrierId,
    ): array {
        return [
            'seq' => $seq,
            'from_node_id' => $fromNodeId,
            'to_node_id' => $toNodeId,
            'from_department' => $fromDepartment,
            'to_department' => $toDepartment,
            'haulage_mode' => $haulageMode,
            'carrier_id' => $carrierId,
        ];
    }
}
