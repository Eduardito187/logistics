<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Libro de la idempotencia POR ÍTEM de los lotes de escaneos (POST /app/deliveries/scans/batch).
 *
 * Cada ítem trae su propia clave de idempotencia. Antes de ejecutarlo se pregunta si esa clave ya se
 * aplicó (por un lote anterior O por su endpoint individual: los escaneos guardan la clave en el hecho
 * inmutable); si sí, el ítem responde `duplicate` sin volver a ejecutarse. Un ítem rechazado NO queda
 * bloqueado: reintentarlo con la misma clave lo vuelve a evaluar (el estado pudo cambiar).
 */
interface ScanBatchLedger
{
    /**
     * ¿La clave ya se aplicó con éxito (lote previo o escaneo individual)?
     */
    public function wasAccepted(string $idempotencyKey): bool;

    /**
     * Registra el resultado del ítem (accepted | rejected) por su clave. Upsert: el último resultado gana.
     */
    public function remember(
        string $idempotencyKey,
        string $kind,
        ?string $shipmentId,
        string $result,
        ?string $problemCode,
        ?string $userId,
    ): void;
}
