<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Labels\Application\Query\GetPublicKeysBundle\GetPublicKeysBundle;
use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Distribución del bundle de claves públicas a la app (puerta /api/v1/app/*).
 *
 * La app trae estas claves para verificar la firma Ed25519 de los QR sin conexión. Solo salen las
 * claves públicas; la privada jamás se expone. Solo habla con el QueryBus.
 */
final readonly class PublicKeyController
{
    public function __construct(private QueryBus $queryBus) {}

    public function __invoke(): JsonResponse
    {
        /** @var PublicKeyBundleView $bundle */
        $bundle = $this->queryBus->ask(new GetPublicKeysBundle);

        return new JsonResponse(['data' => $bundle->toArray()]);
    }
}
