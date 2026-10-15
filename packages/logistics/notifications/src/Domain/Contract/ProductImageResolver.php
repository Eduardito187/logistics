<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;

/**
 * Puerto de dominio: dado el id de un envío, devuelve sus productos con nombre, cantidad y URL de
 * imagen (thumbnail). Lo consumen el email (listado con imágenes) y el push (collage).
 *
 * La implementación vive en Infrastructure: lee lo que haya en las líneas del pedido (p. ej.
 * `product_image_url`); si no hay imagen, devuelve el producto con imagen nula para que el canal
 * degrade a un placeholder. Es solo lectura y no habla con sistemas externos de forma síncrona en
 * un request de usuario: corre en el relay del outbox, igual que el envío.
 */
interface ProductImageResolver
{
    /**
     * @return list<ProductThumbnail> Productos del envío (vacío si no se pudieron resolver).
     */
    public function forShipment(string $shipmentId): array;
}
