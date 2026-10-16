<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

/**
 * Puerto de dominio: guarda el PNG del collage de productos y devuelve una URL pública estable para
 * ponerla en el campo `image` del payload de push (FCM) o de Telegram.
 *
 * La implementación vive en Infrastructure (disco local en dev, S3 en producción). Es idempotente por
 * envío: el mismo envío debe resolver a la misma ruta, para no acumular PNGs en reintentos del relay.
 */
interface CollageImageStore
{
    /**
     * Guarda los bytes PNG del collage del envío y devuelve su URL pública.
     */
    public function store(string $shipmentId, string $pngBytes): string;
}
