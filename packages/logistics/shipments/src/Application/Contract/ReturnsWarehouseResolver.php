<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

/**
 * Resuelve el almacén central al que vuelve la mercadería cuando una cancelación requiere logística
 * inversa (envío ya cargado o entregado). La implementación lo toma de la red; el id configurado por
 * env tiene precedencia (ver OrderCancelledFulfillmentDispatcher).
 */
interface ReturnsWarehouseResolver
{
    /** Id del almacén central de retornos, o null si no hay ninguno registrado. */
    public function resolve(): ?string;
}
