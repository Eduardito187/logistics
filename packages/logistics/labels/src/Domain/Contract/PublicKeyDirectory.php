<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

/**
 * Directorio de claves públicas vigentes para verificación offline.
 *
 * La app trae este bundle (clave activa + anteriores aún aceptadas) para validar la firma de los QR
 * sin conexión. Es la contraparte de distribución del firmante: aquí NUNCA sale la clave privada.
 */
interface PublicKeyDirectory
{
    /**
     * Claves públicas vigentes como `id => clave pública en base64`, ordenadas por id.
     *
     * @return array<int, non-empty-string>
     */
    public function bundle(): array;
}
