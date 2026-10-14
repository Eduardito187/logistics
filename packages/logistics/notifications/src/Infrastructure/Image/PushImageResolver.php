<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Image;

use Logistics\Notifications\Domain\Contract\CollageImageBuilder;
use Logistics\Notifications\Domain\Contract\CollageImageStore;
use Logistics\Notifications\Domain\Contract\ProductImageResolver;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resuelve la URL de la imagen a adjuntar en un push (ntfy/web push) o en Telegram: toma los
 * productos del envío, arma el collage con GD y lo guarda en el store, devolviendo su URL pública.
 *
 * Degrada sin romper: si no hay productos con imagen, si GD no está o si falla la construcción/guardado,
 * devuelve null y el canal manda la notificación sin imagen. Corre en el relay del outbox.
 */
final readonly class PushImageResolver
{
    public function __construct(
        private ProductImageResolver $products,
        private CollageImageBuilder $builder,
        private CollageImageStore $store,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * URL de la imagen collage del envío, o null si no se pudo armar (degradar a push sin imagen).
     */
    public function imageUrlForShipment(string $shipmentId): ?string
    {
        try {
            $products = $this->products->forShipment($shipmentId);
            if ($products === []) {
                return null;
            }

            $png = $this->builder->build($products);
            if ($png === null) {
                return null;
            }

            return $this->store->store($shipmentId, $png);
        } catch (Throwable $e) {
            $this->logger?->warning('No se pudo armar el collage de productos del push', [
                'shipment_id' => $shipmentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
