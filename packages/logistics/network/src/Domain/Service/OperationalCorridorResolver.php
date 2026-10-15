<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Service;

use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\NodeType;
use Logistics\Network\Domain\ValueObject\OperationalCorridor;

/**
 * Servicio de dominio PURO: dados los nodos y los tramos de la red, resuelve qué corredores están
 * operativos en un departamento. Es el cálculo que alimenta la consulta homónima (insumo de
 * planning/promise). Determinista, sin I/O ni dependencias de Laravel.
 *
 * Un corredor está operativo en un departamento cuando:
 *  - (tramos entre nodos) existe un Leg cuyo nodo de origen está activo y es de ese departamento, y
 *    cuyo nodo de destino también está activo; cubre CdToCd, CdToStore, StoreToStore y StoreToCd.
 *  - (corredores terminales) hay un nodo activo de ese departamento capaz de atender al cliente por
 *    sí mismo: un CD ofrece CdToCustomer (última milla) y una tienda ofrece StorePickup (recojo).
 *
 * Así quedan representados los seis tipos de corredor por departamento.
 */
final readonly class OperationalCorridorResolver
{
    /**
     * @param  list<Node>                $nodes
     * @param  list<Leg>                 $legs
     * @return list<OperationalCorridor>
     */
    public function inDepartment(array $nodes, array $legs, Department $department): array
    {
        // Índice de nodos ACTIVOS por id: solo ellos participan de corredores operativos.
        $activeById = [];
        foreach ($nodes as $node) {
            if ($node->isActive()) {
                $activeById[$node->id()->toString()] = $node;
            }
        }

        /** @var list<OperationalCorridor> $corridors */
        $corridors = [];

        // Corredores terminales: cada nodo activo del departamento que atiende al cliente por sí mismo.
        foreach ($activeById as $node) {
            if ($node->department() !== $department) {
                continue;
            }

            $terminal = match ($node->type()) {
                NodeType::DistributionCenter => CorridorType::CdToCustomer,
                NodeType::Store => CorridorType::StorePickup,
                NodeType::PickupPoint => null,
            };

            if ($terminal !== null) {
                $corridors[] = OperationalCorridor::terminal($terminal, $department, $node);
            }
        }

        // Tramos entre nodos: origen activo y del departamento, destino activo.
        foreach ($legs as $leg) {
            $origin = $activeById[$leg->from()->toString()] ?? null;
            $destination = $activeById[$leg->to()->toString()] ?? null;
            if ($origin === null || $destination === null) {
                continue;
            }
            if ($origin->department() !== $department) {
                continue;
            }

            $corridors[] = OperationalCorridor::betweenNodes($leg->corridor(), $department, $origin, $destination);
        }

        // Orden estable y determinista: por código de origen, luego corredor, luego código de destino.
        usort($corridors, static function (OperationalCorridor $a, OperationalCorridor $b): int {
            return [$a->origin->code()->toString(), $a->corridor->value, $a->destination?->code()->toString() ?? '']
                <=> [$b->origin->code()->toString(), $b->corridor->value, $b->destination?->code()->toString() ?? ''];
        });

        return $corridors;
    }
}
