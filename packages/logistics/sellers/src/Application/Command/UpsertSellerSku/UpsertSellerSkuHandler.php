<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\UpsertSellerSku;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga el seller, valida la invariante (un seller suspendido no da de alta SKUs), persiste el SKU fila
 * a fila y publica el evento al outbox.
 *
 * El SKU se persiste con `saveSku` (no con `save`) porque el catálogo es una proyección subordinada de
 * gran volumen que no vive dentro del agregado.
 *
 * @implements CommandHandler<UpsertSellerSku>
 */
final readonly class UpsertSellerSkuHandler implements CommandHandler
{
    public function __construct(
        private SellerRepository $sellers,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof UpsertSellerSku);

        $seller = $this->sellers->findById(SellerId::fromString($command->sellerId));
        if ($seller === null) {
            throw SellersException::because("No existe el seller {$command->sellerId}.");
        }

        $sku = SellerSku::of($command->sku, $command->sapMaterial);

        // Valida la regla de negocio y graba el evento en el agregado.
        $seller->upsertSku($sku);

        // Persiste la fila del catálogo (upsert por clave natural seller_id + sku).
        $this->sellers->saveSku($seller->id(), $sku);

        $this->recordEvents->record($seller->releaseEvents(), 'sellers.seller');
    }
}
