<?php

declare(strict_types=1);

namespace Logistics\Labels\Tests\Support;

use Logistics\Labels\Domain\ValueObject\Fraction;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Infrastructure\Crypto\SodiumLabelSigner;

final class LabelFixtures
{
    /** Semilla fija de 32 bytes: Ed25519 es determinista, así la firma del vector es reproducible. */
    public const string SEED = 'logistics-label-test-vector-001!';

    public const string VECTOR_PATH = __DIR__.'/../Fixtures/label-test-vector.json';

    public static function payload(int $keyId = 1): LabelPayload
    {
        return LabelPayload::forPackage(
            keyId: $keyId,
            id: '01JB8ZQ4X7K2M9N5P6R7S8T9VW',
            orderNumber: 'DM-260012345',
            lineNumber: 3,
            sku: 'LG-GT32WPP',
            unit: Fraction::of(1, 2),
            piece: Fraction::single(),
            origin: 'CD-SCZ',
            destination: LabelPayload::CUSTOMER_DESTINATION,
            issuedAt: 1759312800,
        );
    }

    public static function signer(int $keyId = 1, string $seed = self::SEED): SodiumLabelSigner
    {
        return SodiumLabelSigner::fromSeed($keyId, $seed);
    }

    /**
     * @return array{key_id: int, public_key_base64: string, canonical: string, qr_content: string, tampered_qr_content: string}
     */
    public static function vector(): array
    {
        $json = file_get_contents(self::VECTOR_PATH);
        assert(is_string($json));

        /** @var array{key_id: int, public_key_base64: string, canonical: string, qr_content: string, tampered_qr_content: string} */
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
