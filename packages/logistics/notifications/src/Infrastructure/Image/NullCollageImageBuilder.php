<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Image;

use Logistics\Notifications\Domain\Contract\CollageImageBuilder;

/**
 * Stub del armador de collage: nunca produce imagen (siempre null), así los push salen sin imagen.
 * Es el binding de respaldo para entornos sin GD o para desactivar el collage por configuración.
 */
final class NullCollageImageBuilder implements CollageImageBuilder
{
    public function build(array $products): ?string
    {
        return null;
    }
}
