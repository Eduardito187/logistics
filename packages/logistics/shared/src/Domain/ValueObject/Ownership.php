<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Propiedad de una unidad física / envío: a quién pertenece el producto y bajo qué modo de fulfillment
 * entró a la operación. Es un value object transversal (vive en `shared` porque lo usan varios contextos:
 * shipments, inbound, inventory, settlement) que VIAJA con la unidad — ver ADR 017.
 *
 * El dueño se guarda como `Uuid` GENÉRICO a propósito: `shared` no puede depender del contexto `sellers`
 * (sería dependencia al revés). El id apunta al seller, pero es el contexto `sellers` quien lo interpreta.
 *
 * Invariante: retail (`Owned`) ⇒ sin dueño; marketplace (`Fbd`/`Dropship`) ⇒ con dueño. Inmutable.
 */
final readonly class Ownership
{
    private function __construct(
        public FulfillmentMode $mode,
        public ?Uuid $ownerPartyId,
        public ?string $sellerSkuRef,
    ) {
        if ($mode->requiresOwner() && $ownerPartyId === null) {
            throw new InvalidArgumentException(
                sprintf('Fulfillment mode "%s" requires an owner party id.', $mode->value),
            );
        }

        if (!$mode->requiresOwner() && $ownerPartyId !== null) {
            throw new InvalidArgumentException('Owned (retail) stock cannot have an owner party id.');
        }
    }

    /**
     * Producto retail de Dismac (comportamiento por defecto de toda la plataforma).
     */
    public static function dismacOwned(): self
    {
        return new self(FulfillmentMode::Owned, null, null);
    }

    /**
     * Producto de un seller que Dismac recibe y distribuye (Fulfilled by Dismac).
     */
    public static function sellerFbd(Uuid $ownerPartyId, ?string $sellerSkuRef = null): self
    {
        return new self(FulfillmentMode::Fbd, $ownerPartyId, $sellerSkuRef);
    }

    /**
     * Producto de un seller que el seller despacha directamente (dropship).
     */
    public static function sellerDropship(Uuid $ownerPartyId, ?string $sellerSkuRef = null): self
    {
        return new self(FulfillmentMode::Dropship, $ownerPartyId, $sellerSkuRef);
    }

    /**
     * Reconstitución desde primitivos (para mappers de Infrastructure). `$mode` es el valor del enum.
     */
    public static function fromPrimitives(string $mode, ?string $ownerPartyId, ?string $sellerSkuRef): self
    {
        $fulfillmentMode = FulfillmentMode::from($mode);

        return new self(
            $fulfillmentMode,
            $ownerPartyId !== null ? Uuid::fromString($ownerPartyId) : null,
            $sellerSkuRef,
        );
    }

    public function isOwned(): bool
    {
        return $this->mode === FulfillmentMode::Owned;
    }

    public function isMarketplace(): bool
    {
        return $this->mode->isMarketplace();
    }

    public function equals(self $other): bool
    {
        return $this->mode === $other->mode
            && $this->sellerSkuRef === $other->sellerSkuRef
            && (
                ($this->ownerPartyId === null && $other->ownerPartyId === null)
                || ($this->ownerPartyId !== null && $other->ownerPartyId !== null
                    && $this->ownerPartyId->equals($other->ownerPartyId))
            );
    }

    /**
     * Forma primitiva para persistir en columnas aditivas (owner_party_id nullable + fulfillment_mode).
     *
     * @return array{mode: string, owner_party_id: ?string, seller_sku_ref: ?string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'owner_party_id' => $this->ownerPartyId?->value,
            'seller_sku_ref' => $this->sellerSkuRef,
        ];
    }
}
