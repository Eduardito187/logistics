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
     * Claves públicas vigentes como `id => lista de claves públicas en base64`, ordenadas por id.
     *
     * Es una LISTA por id (no una sola) para la red de seguridad append-only: un mismo key_id puede
     * tener más de una pública histórica y todas se sirven, de modo que una etiqueta válida nunca se
     * rechace por un cambio de clave. En el caso normal la lista tiene un solo elemento.
     *
     * @return array<int, list<non-empty-string>>
     */
    public function bundle(): array;
}
