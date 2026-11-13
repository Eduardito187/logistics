<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Estado de publicación de un producto en el catálogo.
 *
 * - Draft: en edición, no visible en el storefront.
 * - Published: visible y comprable.
 * - Archived: retirado (no comprable), se conserva el histórico.
 */
enum ProductStatus: string
{
    use CaseInsensitiveEnum;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
