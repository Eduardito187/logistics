<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;

/**
 * Puerto de dominio: arma una imagen tipo collage con las imágenes de los productos de un envío, para
 * el push (FCM) y, opcionalmente, para Telegram.
 *
 * Regla de composición (pedido del dueño): hasta 4 productos en grilla; si hay más de 4, se muestran
 * los primeros 3 y una cuarta celda "+N" con el resto. La implementación real usa GD; si GD no está
 * disponible o no hay imágenes utilizables, DEBE devolver null (degradación: push sin imagen), nunca
 * lanzar.
 */
interface CollageImageBuilder
{
    /**
     * @param  list<ProductThumbnail> $products
     * @return string|null            Bytes PNG del collage, o null si no se pudo construir (degradar sin imagen).
     */
    public function build(array $products): ?string;
}
