<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Support;

use Logistics\Execution\Application\Query\GetDeliveryEvidence\GetDeliveryEvidence;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Galería de la evidencia de una entrega, lista para MOSTRAR: firma del cliente, foto(s) de la entrega y
 * documento del cliente, cada una con su URL visible ({@see EvidenceFileUrl}).
 *
 * Junta las dos fuentes por el QueryBus: la evidencia capturada (POST /app/deliveries/evidence) y las fotos
 * ligadas a la prueba de entrega (POST /app/deliveries/photos o la foto inline del POD). La usan la API de
 * la app y la ficha del pedido en el admin. Tolerante: una fuente caída no rompe la otra; sin archivos
 * devuelve lista vacía.
 *
 * @phpstan-type GalleryItem array{kind: string, url: string, captured_at: string|null, validation_status: string|null}
 */
final readonly class DeliveryEvidenceGallery
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    /**
     * @return list<GalleryItem>
     */
    public function forShipment(string $shipmentId, ?DeliveryView $delivery = null): array
    {
        $items = [];
        $seen = [];

        try {
            /** @var list<DeliveryEvidenceView> $evidence */
            $evidence = $this->queryBus->ask(new GetDeliveryEvidence($shipmentId));
        } catch (Throwable) {
            $evidence = [];
        }

        foreach ($evidence as $view) {
            $url = EvidenceFileUrl::forDisk($view->storageDisk, $view->storagePath);
            if ($url === null || isset($seen[$view->storagePath])) {
                continue;
            }
            $seen[$view->storagePath] = true;
            $items[] = [
                'kind' => $view->kind,
                'url' => $url,
                'captured_at' => $view->capturedAt,
                'validation_status' => $view->validationStatus,
            ];
        }

        if ($delivery === null) {
            try {
                $state = $this->queryBus->ask(new GetDeliveryState($shipmentId));
                $delivery = $state instanceof DeliveryView ? $state : null;
            } catch (Throwable) {
                $delivery = null;
            }
        }

        if ($delivery !== null) {
            $paths = $delivery->podPhotoPaths;
            if ($delivery->podPhotoPath !== null && $delivery->podPhotoPath !== '') {
                array_unshift($paths, $delivery->podPhotoPath);
            }

            foreach ($paths as $path) {
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;

                $url = EvidenceFileUrl::forPath($path);
                if ($url !== null) {
                    $items[] = ['kind' => 'pod', 'url' => $url, 'captured_at' => null, 'validation_status' => null];
                }
            }

            // Foto por URL externa (integraciones): se muestra tal cual si no hay ninguna foto propia.
            $external = $delivery->podPhotoUrl;
            if ($external !== null && $external !== '' && !self::hasKind($items, 'pod')) {
                $items[] = ['kind' => 'pod', 'url' => $external, 'captured_at' => null, 'validation_status' => null];
            }
        }

        return $items;
    }

    /**
     * @param list<GalleryItem> $items
     */
    private static function hasKind(array $items, string $kind): bool
    {
        foreach ($items as $item) {
            if ($item['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }
}
