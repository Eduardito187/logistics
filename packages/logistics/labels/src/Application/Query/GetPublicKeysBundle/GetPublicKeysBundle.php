<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetPublicKeysBundle;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el bundle de claves públicas vigentes para que la app verifique firmas de QR sin conexión.
 *
 * No lleva parámetros: siempre devuelve el conjunto completo (activa + anteriores aceptadas).
 */
final readonly class GetPublicKeysBundle implements Query {}
