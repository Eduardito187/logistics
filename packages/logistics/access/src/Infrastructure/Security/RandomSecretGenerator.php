<?php

declare(strict_types=1);

namespace Logistics\Access\Infrastructure\Security;

use Illuminate\Support\Str;
use Logistics\Access\Domain\Contract\SecretGenerator;
use Logistics\Access\Domain\ValueObject\PlainTokenSecret;

/**
 * Implementación del puerto {@see SecretGenerator} sobre el generador aleatorio seguro del framework.
 *
 * El prefijo "itk_" (integration token key) es público e indexable; el secreto es la parte sensible.
 * Ambos salen de `Str::random`, que usa un CSPRNG. El cliente recibe "itk_xxxxxxxx.<secreto>".
 */
final class RandomSecretGenerator implements SecretGenerator
{
    private const PREFIX_TAG = 'itk_';

    private const PREFIX_RANDOM_LENGTH = 12;

    private const SECRET_LENGTH = 48;

    public function generate(): PlainTokenSecret
    {
        $prefix = self::PREFIX_TAG.Str::lower(Str::random(self::PREFIX_RANDOM_LENGTH));
        $secret = Str::random(self::SECRET_LENGTH);

        return PlainTokenSecret::of($prefix, $secret);
    }
}
