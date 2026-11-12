<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetPublicKeysBundle;

use Illuminate\Contracts\Config\Repository;
use Logistics\Labels\Application\ReadModel\PublicKeyBundleView;
use Logistics\Labels\Domain\Contract\PublicKeyDirectory;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el bundle de claves públicas vigentes como modelo de lectura, con la señal de modo ABIERTO.
 *
 * En modo abierto la app NO verifica la firma de ningún QR (ni las k:1 ya impresas) y valida por
 * pertenencia (orden): así una etiqueta impresa nunca se rechaza. Modo abierto = LABELS_OPEN=true o sin
 * LABELS_SIGNING_KEY (misma regla que el firmante efectivo del provider).
 *
 * @implements QueryHandler<GetPublicKeysBundle, PublicKeyBundleView>
 */
final readonly class GetPublicKeysBundleHandler implements QueryHandler
{
    public function __construct(
        private PublicKeyDirectory $directory,
        private Repository $config,
    ) {}

    public function handle(Query $query): PublicKeyBundleView
    {
        assert($query instanceof GetPublicKeysBundle);

        $key = $this->config->get('logistics-labels.signing_key');
        $open = (bool) $this->config->get('logistics-labels.open', true) || !is_string($key) || $key === '';

        return new PublicKeyBundleView($this->directory->bundle(), $open);
    }
}
