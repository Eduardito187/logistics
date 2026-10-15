<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Service;

/**
 * Servicio de dominio PURO de rentabilidad (ROADMAP 3-06): margen = ingreso − costo.
 *
 * A diferencia de Money (que prohíbe negativos porque un importe operativo no puede serlo), el MARGEN sí
 * puede ser negativo (pérdida), por eso se maneja como entero de centavos con signo y no como Money.
 *
 * El ingreso es OPCIONAL: hoy la plataforma no factura entregas, así que el ingreso llega como entrada
 * configurable (settings/env) o null. Cuando el ingreso es null el margen es null (no se inventa ingreso):
 * el read-model queda listo para enchufar el ingreso real cuando exista facturación.
 */
final readonly class ProfitabilityCalculator
{
    /**
     * Margen en centavos (ingreso − costo). Null si no hay ingreso provisto. Puede ser negativo (pérdida).
     */
    public function marginCents(?int $revenueCents, int $costCents): ?int
    {
        if ($revenueCents === null) {
            return null;
        }

        return $revenueCents - $costCents;
    }

    /**
     * Ratio de margen (margen / ingreso), redondeado a 4 decimales. Null si no hay ingreso o es cero
     * (no se puede dividir por cero). Positivo = ganancia, negativo = pérdida.
     */
    public function marginRatio(?int $revenueCents, int $costCents): ?float
    {
        if ($revenueCents === null || $revenueCents === 0) {
            return null;
        }

        return round(($revenueCents - $costCents) / $revenueCents, 4);
    }
}
