<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Lector del catálogo de motivos de entrega fallida, para VALIDAR el flujo de entrega fallida contra el
 * catálogo ACTIVO sin cargar el agregado completo. Lo consume el handler de RecordFailedDelivery.
 */
interface FailureReasonCatalog
{
    /**
     * ¿El code corresponde a un motivo del catálogo que está ACTIVO? (desconocido o inactivo → false).
     */
    public function isActive(string $code): bool;

    /**
     * Exigencias del motivo elegido (foto/nota obligatorias). Un code desconocido devuelve ambas en false.
     *
     * @return array{requires_photo: bool, requires_note: bool}
     */
    public function requirements(string $code): array;
}
