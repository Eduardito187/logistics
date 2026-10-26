<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Product;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Notifications\Domain\Contract\ProductImageResolver;
use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;
use Throwable;

/**
 * Implementación stub del resolutor de productos: lee las líneas del pedido de la tabla
 * `shipment_lines` del contexto Envíos (nombre, SKU, cantidad y `product_image_url` si existe). Si una
 * línea no trae imagen, usa el placeholder configurado, para que email y push siempre tengan algo que
 * pintar. El SKU viaja junto al nombre para mostrarse en el listado de productos del email.
 *
 * Consulta por nombre de tabla con el query builder (no acopla contra el modelo Eloquent de otro
 * package) y degrada a lista vacía si la tabla no existe o la consulta falla: el canal entonces manda
 * la notificación sin contenido rico, sin romper.
 */
final readonly class LineItemProductImageResolver implements ProductImageResolver
{
    public function __construct(
        private ConnectionResolverInterface $db,
        private ?string $placeholderUrl = null,
        private int $maxLines = 20,
    ) {}

    public function forShipment(string $shipmentId): array
    {
        try {
            $rows = $this->db->connection()
                ->table('shipment_lines')
                ->where('shipment_id', $shipmentId)
                ->orderBy('product_name')
                ->limit($this->maxLines)
                ->get(['product_name', 'sku', 'quantity', 'product_image_url']);
        } catch (Throwable) {
            return [];
        }

        $products = [];
        foreach ($rows as $row) {
            /** @var object{product_name?: string, sku?: string|null, quantity?: int, product_image_url?: string|null} $row */
            $name = isset($row->product_name) ? (string) $row->product_name : '';
            if (trim($name) === '') {
                continue;
            }
            $qty = isset($row->quantity) ? max(1, (int) $row->quantity) : 1;
            $imageUrl = isset($row->product_image_url) && is_string($row->product_image_url) && trim($row->product_image_url) !== ''
                ? $row->product_image_url
                : $this->placeholderUrl;
            $sku = isset($row->sku) && is_string($row->sku) && trim($row->sku) !== ''
                ? $row->sku
                : null;

            $products[] = ProductThumbnail::of($name, $qty, $imageUrl, $sku);
        }

        return $products;
    }
}
