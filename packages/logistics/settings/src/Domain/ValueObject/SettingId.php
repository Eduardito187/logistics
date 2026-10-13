<?php

declare(strict_types=1);

namespace Logistics\Settings\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Id tipado de una versión de configuración.
 *
 * Es el UUID que identifica una fila concreta de `settings_versions`: una (clave, versión) puntual,
 * no la clave en abstracto.
 */
final readonly class SettingId extends AbstractId {}
