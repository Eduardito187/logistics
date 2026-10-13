<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\Contract;

use Logistics\Labels\Domain\ValueObject\SignedLabel;
use Logistics\Labels\Domain\ValueObject\VerificationResult;

interface LabelVerifier
{
    public function verify(SignedLabel $label): VerificationResult;
}
