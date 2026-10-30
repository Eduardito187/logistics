<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Command\SetPrice;

use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\Sku;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la lista, valida las invariantes (misma moneda; una lista archivada no admite precios), persiste
 * el precio fila a fila y publica el evento al outbox.
 *
 * El precio se persiste con `setPrice` (no con `save`) porque el catálogo de precios es una proyección
 * subordinada de gran volumen que no vive dentro del agregado.
 *
 * @implements CommandHandler<SetPrice>
 */
final readonly class SetPriceHandler implements CommandHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
        private RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof SetPrice);

        $list = $this->priceLists->findById(PriceListId::fromString($command->priceListId));
        if ($list === null) {
            throw PricingException::because("No existe la lista de precios {$command->priceListId}.");
        }

        $sku = Sku::of($command->sku);
        $grossAmount = Money::of($command->grossAmountCents, $command->currency);

        // Valida las reglas de negocio y graba el evento en el agregado.
        $list->setPrice($sku, $grossAmount);

        // Persiste la fila del precio (upsert por clave natural price_list_id + sku).
        $this->priceLists->setPrice($list->id(), $sku, $grossAmount);

        $this->recordEvents->record($list->releaseEvents(), 'pricing.price_list');
    }
}
