<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * URL de la imagen del producto de una línea, tal como llega de la fuente (catálogo Magento, CDN, etc.).
 *
 * Es un dato OPCIONAL de presentación: sirve para que las notificaciones al cliente (mail, push) y el
 * admin muestren una miniatura del producto. El contexto no descarga ni valida la imagen; solo guarda
 * la URL si viene http(s). Si la fuente no la manda, la línea queda sin imagen (null) sin romper nada.
 */
final readonly class ProductImageUrl
{
    private const MAX_LENGTH = 2048;

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw ShipmentsException::because('la URL de imagen del producto no puede estar vacía');
        }
        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw ShipmentsException::because('la URL de imagen del producto excede '.self::MAX_LENGTH.' caracteres');
        }
        if (!str_starts_with($trimmed, 'http://') && !str_starts_with($trimmed, 'https://')) {
            throw ShipmentsException::because("la URL de imagen del producto debe ser http(s): {$trimmed}");
        }

        return new self($trimmed);
    }

    /**
     * Named constructor tolerante para la ingesta: devuelve null si no hay valor, en vez de lanzar.
     */
    public static function fromNullable(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : self::of($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
