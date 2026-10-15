<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetPublicKeysBundle;

use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;
use Logistics\Labels\Domain\Contract\PublicKeyDirectory;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el bundle de claves públicas vigentes como modelo de lectura.
 *
 * @implements QueryHandler<GetPublicKeysBundle, PublicKeyBundleView>
 */
final readonly class GetPublicKeysBundleHandler implements QueryHandler
{
    public function __construct(private PublicKeyDirectory $directory) {}

    public function handle(Query $query): PublicKeyBundleView
    {
        assert($query instanceof GetPublicKeysBundle);

        return new PublicKeyBundleView($this->directory->bundle());
    }
}
