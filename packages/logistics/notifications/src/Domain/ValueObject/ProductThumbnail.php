<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Producto de un envío listo para pintarse en una notificación rica: nombre, SKU, cantidad y URL de la
 * imagen (thumbnail). Lo usa el email (listado con nombre + SKU e imágenes) y el push (collage de
 * imágenes).
 *
 * Inmutable. La URL de imagen es opcional: si el pedido no trae imagen, el canal degrada a un
 * placeholder o a un envío sin imagen, nunca rompe. El SKU también es opcional a nivel de VO, pero
 * cuando viene de las líneas del pedido siempre está presente y se muestra junto al nombre.
 */
final readonly class ProductThumbnail
{
    private function __construct(
        public string $name,
        public int $qty,
        public ?string $imageUrl,
        public ?string $sku = null,
    ) {}

    public static function of(string $name, int $qty, ?string $imageUrl = null, ?string $sku = null): self
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw NotificationsException::because('el producto de la notificación necesita un nombre');
        }
        if ($qty < 1) {
            throw NotificationsException::because('la cantidad del producto debe ser al menos 1');
        }

        $trimmedUrl = $imageUrl !== null ? trim($imageUrl) : null;
        if ($trimmedUrl === '') {
            $trimmedUrl = null;
        }

        $trimmedSku = $sku !== null ? trim($sku) : null;
        if ($trimmedSku === '') {
            $trimmedSku = null;
        }

        return new self($trimmedName, $qty, $trimmedUrl, $trimmedSku);
    }

    /**
     * Forma primitiva para pasarla a una vista o cachearla (regla 7 de CLAUDE.md).
     *
     * @return array{name: string, qty: int, imageUrl: string|null, sku: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'qty' => $this->qty,
            'imageUrl' => $this->imageUrl,
            'sku' => $this->sku,
        ];
    }
}
