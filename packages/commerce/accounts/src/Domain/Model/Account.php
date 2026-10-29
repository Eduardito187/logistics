<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Model;

use Commerce\Accounts\Domain\Event\AccountActivated;
use Commerce\Accounts\Domain\Event\AccountRegistered;
use Commerce\Accounts\Domain\Event\AccountSuspended;
use Commerce\Accounts\Domain\Event\AddressAdded;
use Commerce\Accounts\Domain\Event\AddressRemoved;
use Commerce\Accounts\Domain\Exception\AccountsException;
use Commerce\Accounts\Domain\ValueObject\AccountId;
use Commerce\Accounts\Domain\ValueObject\AccountStatus;
use Commerce\Accounts\Domain\ValueObject\AccountType;
use Commerce\Accounts\Domain\ValueObject\Address;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cuenta comercial (ADR 018, Fase 0 de comercio, EC-F0-04).
 *
 * Modela el concepto COMERCIAL de cliente del storefront: tipo de cuenta (persona/empresa/seller-persona),
 * nombre comercial, NIT (empresas), estado comercial y direcciones de compra. NO es la identidad/authN:
 * `access` sigue siendo la autoridad de usuarios/tokens/SSO/MFA; aquí se referencia al usuario de `access`
 * por `Uuid` (`userId`), nunca se lo modifica.
 *
 * Las direcciones son value objects que viven DENTRO del agregado (se persisten como JSON en la fila de
 * la cuenta), no entidades con identidad propia: la cuenta es la frontera de consistencia.
 */
final class Account extends AggregateRoot
{
    /**
     * @param list<Address> $addresses
     */
    private function __construct(
        private readonly AccountId $id,
        private readonly AccountType $type,
        private readonly Uuid $userId,
        private string $displayName,
        private ?string $taxId,
        private AccountStatus $status,
        private array $addresses,
    ) {}

    /**
     * Registra una cuenta comercial nueva (activa). Si el tipo es Company, el NIT (taxId) es obligatorio.
     */
    public static function register(
        AccountId $id,
        AccountType $type,
        Uuid $userId,
        string $displayName,
        ?string $taxId = null,
    ): self {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw AccountsException::because('El nombre de la cuenta no puede estar vacío.');
        }
        $taxId = $taxId !== null ? trim($taxId) : null;
        $taxId = $taxId === '' ? null : $taxId;
        if ($type === AccountType::Company && $taxId === null) {
            throw AccountsException::because('Una cuenta de empresa requiere NIT (taxId).');
        }

        $account = new self(
            $id,
            $type,
            $userId,
            $displayName,
            $taxId,
            AccountStatus::Active,
            [],
        );
        $account->recordEvent(new AccountRegistered(
            $id->toString(),
            $type->value,
            $userId->value,
            $displayName,
            $taxId,
        ));

        return $account;
    }

    /**
     * Reconstituye una cuenta desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<Address> $addresses
     */
    public static function reconstitute(
        AccountId $id,
        AccountType $type,
        Uuid $userId,
        string $displayName,
        ?string $taxId,
        AccountStatus $status,
        array $addresses,
    ): self {
        return new self(
            $id,
            $type,
            $userId,
            $displayName,
            $taxId,
            $status,
            $addresses,
        );
    }

    public function rename(string $displayName): void
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw AccountsException::because('El nombre de la cuenta no puede estar vacío.');
        }
        $this->displayName = $displayName;
    }

    /**
     * Agrega una dirección. Si viene marcada como predeterminada, desmarca a las demás (a lo sumo una
     * dirección es la predeterminada). La etiqueta identifica la dirección dentro de la cuenta.
     */
    public function addAddress(Address $address): void
    {
        foreach ($this->addresses as $existing) {
            if ($existing->hasLabel($address->label)) {
                throw AccountsException::because(
                    "La cuenta ya tiene una dirección con la etiqueta {$address->label}.",
                );
            }
        }

        if ($address->isDefault) {
            $this->addresses = array_values(array_map(
                static fn (Address $existing): Address => $existing->asNonDefault(),
                $this->addresses,
            ));
        }

        $this->addresses[] = $address;
        $this->recordEvent(new AddressAdded($this->id->toString(), $address->label, $address->isDefault));
    }

    /**
     * Quita la dirección con la etiqueta dada (idempotente: si no existe, no hace nada).
     */
    public function removeAddress(string $label): void
    {
        $label = trim($label);
        $before = count($this->addresses);
        $this->addresses = array_values(array_filter(
            $this->addresses,
            static fn (Address $address): bool => ! $address->hasLabel($label),
        ));
        if (count($this->addresses) !== $before) {
            $this->recordEvent(new AddressRemoved($this->id->toString(), $label));
        }
    }

    /**
     * Suspende la cuenta (idempotente: si ya está suspendida, no hace nada ni graba evento).
     */
    public function suspend(): void
    {
        if ($this->status === AccountStatus::Suspended) {
            return;
        }
        $this->status = AccountStatus::Suspended;
        $this->recordEvent(new AccountSuspended($this->id->toString()));
    }

    /**
     * Reactiva la cuenta (idempotente: si ya está activa, no hace nada ni graba evento).
     */
    public function activate(): void
    {
        if ($this->status === AccountStatus::Active) {
            return;
        }
        $this->status = AccountStatus::Active;
        $this->recordEvent(new AccountActivated($this->id->toString()));
    }

    public function id(): AccountId
    {
        return $this->id;
    }

    public function type(): AccountType
    {
        return $this->type;
    }

    public function userId(): Uuid
    {
        return $this->userId;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function taxId(): ?string
    {
        return $this->taxId;
    }

    public function status(): AccountStatus
    {
        return $this->status;
    }

    /**
     * @return list<Address>
     */
    public function addresses(): array
    {
        return $this->addresses;
    }

    /**
     * Dirección predeterminada de la cuenta, o null si no hay ninguna marcada.
     */
    public function defaultAddress(): ?Address
    {
        foreach ($this->addresses as $address) {
            if ($address->isDefault) {
                return $address;
            }
        }

        return null;
    }
}
