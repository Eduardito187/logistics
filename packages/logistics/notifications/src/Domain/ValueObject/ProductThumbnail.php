<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\ValueObject;

use Logistics\Notifications\Domain\Exception\NotificationsException;

/**
 * Producto de un envío listo para pintarse en una notificación rica: nombre, cantidad y URL de la
 * imagen (thumbnail). Lo usa el email (listado con imágenes) y el push (collage de imágenes).
 *
 * Inmutable. La URL de imagen es opcional: si el pedido no trae imagen, el canal degrada a un
 * placeholder o a un envío sin imagen, nunca rompe.
 */
final readonly class ProductThumbnail
{
    private function __construct(
        public string $name,
        public int $qty,
        public ?string $imageUrl,
    ) {}

    public static function of(string $name, int $qty, ?string $imageUrl = null): self
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

        return new self($trimmedName, $qty, $trimmedUrl);
    }

    /**
     * Forma primitiva para pasarla a una vista o cachearla (regla 7 de CLAUDE.md).
     *
     * @return array{name: string, qty: int, imageUrl: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'qty' => $this->qty,
            'imageUrl' => $this->imageUrl,
        ];
    }
}
