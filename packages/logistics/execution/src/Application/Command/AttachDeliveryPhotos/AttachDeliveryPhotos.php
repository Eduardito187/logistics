<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AttachDeliveryPhotos;

use Logistics\Shared\Application\Command\Command;

/**
 * Liga una o varias fotos a la entrega de un envío cuando la foto llegó DESPUÉS del POD (opción A: POD
 * asíncrono). Lo dispara la subida de evidencia (`POST /app/deliveries/photos`) tras guardar la foto.
 *
 * Tolerante e idempotente: si el envío no tiene entrega, o no está entregada, o la foto ya estaba ligada,
 * no hace nada. Nunca bloquea la subida.
 */
final readonly class AttachDeliveryPhotos implements Command
{
    /**
     * @param list<string> $photoPaths
     */
    public function __construct(
        public string $shipmentId,
        public array $photoPaths,
    ) {}
}
