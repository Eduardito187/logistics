<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Model;

use Logistics\Sellers\Domain\Event\SellerActivated;
use Logistics\Sellers\Domain\Event\SellerRegistered;
use Logistics\Sellers\Domain\Event\SellerSkuUpserted;
use Logistics\Sellers\Domain\Event\SellerSuspended;
use Logistics\Sellers\Domain\Event\SettlementTermsChanged;
use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Sellers\Domain\ValueObject\SellerStatus;
use Logistics\Sellers\Domain\ValueObject\SettlementTerms;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Seller / proveedor de marketplace: party de primera clase (ADR 017, Fase 1).
 *
 * Mantiene su identidad, nombre legal, NIT, estado del ciclo de vida, contacto y los términos de
 * liquidación pactados. El catálogo de SKUs NO se guarda dentro del agregado: es una proyección
 * subordinada de gran volumen (puede haber millones) que se persiste fila a fila; el agregado solo
 * custodia la invariante (un seller suspendido no da de alta SKUs) y emite el hecho `SellerSkuUpserted`.
 */
final class Seller extends AggregateRoot
{
    private function __construct(
        private readonly SellerId $id,
        private string $legalName,
        private readonly string $taxId,
        private SellerStatus $status,
        private ContactInfo $contact,
        private ?SettlementTerms $settlementTerms,
    ) {}

    /**
     * Da de alta un seller nuevo. Arranca en Onboarding y sin términos de liquidación.
     */
    public static function register(
        SellerId $id,
        string $legalName,
        string $taxId,
        ContactInfo $contact,
    ): self {
        $legalName = trim($legalName);
        if ($legalName === '') {
            throw SellersException::because('El nombre legal del seller no puede estar vacío.');
        }
        $taxId = trim($taxId);
        if ($taxId === '') {
            throw SellersException::because('El NIT del seller no puede estar vacío.');
        }

        $seller = new self($id, $legalName, $taxId, SellerStatus::Onboarding, $contact, null);
        $seller->recordEvent(new SellerRegistered(
            $id->toString(),
            $legalName,
            $taxId,
            SellerStatus::Onboarding->value,
            $contact->contactName,
            $contact->email,
            $contact->phone,
        ));

        return $seller;
    }

    /**
     * Reconstituye un seller desde persistencia, sin grabar eventos (ya ocurrieron).
     */
    public static function reconstitute(
        SellerId $id,
        string $legalName,
        string $taxId,
        SellerStatus $status,
        ContactInfo $contact,
        ?SettlementTerms $settlementTerms,
    ): self {
        return new self($id, $legalName, $taxId, $status, $contact, $settlementTerms);
    }

    /**
     * Activa al seller. Idempotente: si ya está activo, no hace nada ni graba evento.
     */
    public function activate(): void
    {
        if ($this->status === SellerStatus::Active) {
            return;
        }
        $this->status = SellerStatus::Active;
        $this->recordEvent(new SellerActivated($this->id->toString()));
    }

    /**
     * Suspende al seller. Idempotente: si ya está suspendido, no hace nada ni graba evento.
     */
    public function suspend(): void
    {
        if ($this->status === SellerStatus::Suspended) {
            return;
        }
        $this->status = SellerStatus::Suspended;
        $this->recordEvent(new SellerSuspended($this->id->toString()));
    }

    /**
     * Pacta o actualiza los términos de liquidación del seller.
     */
    public function changeSettlementTerms(SettlementTerms $terms): void
    {
        $this->settlementTerms = $terms;
        $this->recordEvent(new SettlementTermsChanged(
            $this->id->toString(),
            $terms->commissionBasisPoints,
            $terms->fixedFee->cents,
            $terms->fixedFee->currency,
            $terms->paymentCycle->value,
        ));
    }

    /**
     * Da de alta o actualiza un SKU del catálogo del seller. Un seller suspendido no puede cargar SKUs.
     *
     * El agregado no guarda el catálogo (lo persiste el repositorio fila a fila); solo valida la regla
     * y emite el hecho para el outbox.
     */
    public function upsertSku(SellerSku $sku): void
    {
        if ($this->status === SellerStatus::Suspended) {
            throw SellersException::because('Un seller suspendido no puede dar de alta SKUs.');
        }

        $this->recordEvent(new SellerSkuUpserted(
            $this->id->toString(),
            $sku->sku,
            $sku->sapMaterial,
        ));
    }

    public function id(): SellerId
    {
        return $this->id;
    }

    public function legalName(): string
    {
        return $this->legalName;
    }

    public function taxId(): string
    {
        return $this->taxId;
    }

    public function status(): SellerStatus
    {
        return $this->status;
    }

    public function contact(): ContactInfo
    {
        return $this->contact;
    }

    public function settlementTerms(): ?SettlementTerms
    {
        return $this->settlementTerms;
    }
}
