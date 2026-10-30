<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Model;

use Commerce\Pricing\Domain\Event\PriceListActivated;
use Commerce\Pricing\Domain\Event\PriceListArchived;
use Commerce\Pricing\Domain\Event\PriceListCreated;
use Commerce\Pricing\Domain\Event\PriceSet;
use Commerce\Pricing\Domain\Exception\PricingException;
use Commerce\Pricing\Domain\ValueObject\Channel;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Commerce\Pricing\Domain\ValueObject\PriceListStatus;
use Commerce\Pricing\Domain\ValueObject\Sku;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Lista de precios: agrupa los precios al público (IVA incluido) de un canal, con su ciclo de vida.
 *
 * Los ÍTEMS de precio (sku → monto) NO viven dentro del agregado: pueden ser millones y se persisten
 * fila a fila vía el repositorio. El agregado solo custodia la identidad de la lista, su moneda, canal y
 * estado, y las invariantes al fijar un precio (misma moneda que la lista; una lista archivada no admite
 * precios). Al fijar un precio emite el hecho `PriceSet` para el outbox.
 */
final class PriceList extends AggregateRoot
{
    private function __construct(
        private readonly PriceListId $id,
        private string $name,
        private readonly string $currency,
        private readonly Channel $channel,
        private PriceListStatus $status,
    ) {}

    /**
     * Crea una lista de precios nueva. Arranca en Draft.
     */
    public static function create(
        PriceListId $id,
        string $name,
        Channel $channel,
        string $currency = Money::DEFAULT_CURRENCY,
    ): self {
        $name = trim($name);
        if ($name === '') {
            throw PricingException::because('El nombre de la lista de precios no puede estar vacío.');
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw PricingException::because('La moneda de la lista de precios no puede estar vacía.');
        }

        $list = new self($id, $name, $currency, $channel, PriceListStatus::Draft);
        $list->recordEvent(new PriceListCreated(
            $id->toString(),
            $name,
            $currency,
            $channel->value,
            PriceListStatus::Draft->value,
        ));

        return $list;
    }

    /**
     * Reconstituye una lista desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        PriceListId $id,
        string $name,
        string $currency,
        Channel $channel,
        PriceListStatus $status,
    ): self {
        return new self($id, $name, $currency, $channel, $status);
    }

    /**
     * Activa la lista. Idempotente: si ya está activa, no hace nada ni graba evento.
     */
    public function activate(): void
    {
        if ($this->status === PriceListStatus::Active) {
            return;
        }
        if ($this->status === PriceListStatus::Archived) {
            throw PricingException::because('No se puede activar una lista de precios archivada.');
        }
        $this->status = PriceListStatus::Active;
        $this->recordEvent(new PriceListActivated($this->id->toString()));
    }

    /**
     * Archiva la lista. Idempotente: si ya está archivada, no hace nada ni graba evento.
     */
    public function archive(): void
    {
        if ($this->status === PriceListStatus::Archived) {
            return;
        }
        $this->status = PriceListStatus::Archived;
        $this->recordEvent(new PriceListArchived($this->id->toString()));
    }

    /**
     * Fija (alta o actualización) el precio al público —IVA incluido— de un SKU en esta lista.
     *
     * El agregado no guarda el precio (lo persiste el repositorio fila a fila); solo valida las
     * invariantes y emite el hecho para el outbox.
     */
    public function setPrice(Sku $sku, Money $grossAmount): void
    {
        if ($this->status === PriceListStatus::Archived) {
            throw PricingException::because('No se puede fijar precio en una lista de precios archivada.');
        }
        if ($grossAmount->currency !== $this->currency) {
            throw PricingException::because(
                "El precio debe estar en la moneda de la lista ({$this->currency}), no en {$grossAmount->currency}.",
            );
        }

        $this->recordEvent(new PriceSet(
            $this->id->toString(),
            $sku->value,
            $grossAmount->cents,
            $grossAmount->currency,
        ));
    }

    public function id(): PriceListId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function status(): PriceListStatus
    {
        return $this->status;
    }
}
