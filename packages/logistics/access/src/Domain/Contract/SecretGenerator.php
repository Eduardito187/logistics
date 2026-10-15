<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Contract;

use Logistics\Access\Domain\ValueObject\PlainTokenSecret;

/**
 * Puerto de generación de secretos de tokens de integración. Produce un prefijo público y un secreto
 * en claro de alta entropía; el dominio no sabe cómo se genera (CSPRNG), solo lo pide.
 *
 * La implementación vive en Infrastructure (usa el generador aleatorio seguro del framework).
 */
interface SecretGenerator
{
    public function generate(): PlainTokenSecret;
}
