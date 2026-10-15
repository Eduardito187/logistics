<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

/**
 * Puerta de lectura de la CARGA de una ruta y la CAPACIDAD de un camión para las métricas de calidad
 * (R-06). El dominio define QUÉ necesita; la implementación (Infrastructure) lee los otros contextos
 * (Envíos para la carga de cada envío, Flota para la capacidad del camión) en forma read-only y en lote,
 * sin acoplar sus esquemas al dominio de Planificación.
 *
 * Compatibilidad/robustez: la implementación degrada a 0 (carga) o null (capacidad) cuando el dato no
 * existe, para que el cálculo de métricas nunca rompa por un envío o un camión sin datos.
 */
interface RouteLoadProfileProvider
{
    /**
     * Carga total (kg y m3) estimada del conjunto de envíos dado, leída en lote. Un envío sin datos suma 0.
     *
     * @param  list<string>                $shipmentIds
     * @return array{kg: float, m3: float}
     */
    public function loadFor(array $shipmentIds): array;

    /**
     * Capacidad nominal (kg y m3) del camión, o null si no se conoce (camión inexistente o sin capacidad).
     *
     * @return array{kg: float, m3: float}|null
     */
    public function capacityFor(string $vehicleId): ?array;
}
