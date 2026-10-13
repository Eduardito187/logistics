<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

interface LabelSigner
{
    /** Id de la clave activa: se graba en el payload ("k") para permitir rotación. */
    public function activeKeyId(): int;

    public function sign(LabelPayload $payload): SignedLabel;
}
