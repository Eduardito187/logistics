<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Model;

use Commerce\Cart\Domain\Event\CartConverted;
use Commerce\Cart\Domain\Event\CartItemAdded;
use Commerce\Cart\Domain\Event\CartMerged;
use Commerce\Cart\Domain\Event\CartOpened;
use Commerce\Cart\Domain\Exception\CartException;
use Commerce\Cart\Domain\ValueObject\CartId;
use Commerce\Cart\Domain\ValueObject\CartLine;
use Commerce\Cart\Domain\ValueObject\CartStatus;
use Commerce\Cart\Domain\ValueObject\Sku;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Carrito de compras del frente comercial (ADR 018, EC-F0-05).
 *
 * Custodia su identidad, dueño (customer; null = invitado identificado por session_id), estado, moneda
 * y líneas. Las líneas son value objects que viven DENTRO del agregado (se persisten como JSON en la
 * fila del carrito): el carrito es la frontera de consistencia.
 *
 * El precio NO se resuelve acá: el precio unitario llega YA calculado en la línea (lo resolvió
 * `pricing`/storefront). La disponibilidad (ATP) se consulta por un puerto en la capa de aplicación,
 * no desde el dominio.
 */
final class Cart extends AggregateRoot
{
    /**
     * @param list<CartLine> $lines
     */
    private function __construct(
        private readonly CartId $id,
        private readonly ?Uuid $customerId,
        private readonly string $sessionId,
        private CartStatus $status,
        private readonly string $currency,
        private array $lines,
    ) {}

    /**
     * Abre un carrito nuevo (estado Open). El dueño (customer) es opcional: null = carrito de invitado.
     */
    public static function open(
        CartId $id,
        ?Uuid $customerId,
        string $sessionId,
        string $currency = 'BOB',
    ): self {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            throw CartException::because('La sesión del carrito no puede estar vacía.');
        }
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            throw CartException::because('La moneda del carrito no puede estar vacía.');
        }

        $cart = new self($id, $customerId, $sessionId, CartStatus::Open, $currency, []);
        $cart->recordEvent(new CartOpened($id->toString(), $customerId?->value, $sessionId, $currency));

        return $cart;
    }

    /**
     * Reconstituye un carrito desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param list<CartLine> $lines
     */
    public static function reconstitute(
        CartId $id,
        ?Uuid $customerId,
        string $sessionId,
        CartStatus $status,
        string $currency,
        array $lines,
    ): self {
        return new self($id, $customerId, $sessionId, $status, $currency, $lines);
    }

    /**
     * Agrega una línea. Si ya existe el mismo SKU, SUMA la cantidad; si no, la agrega. Graba
     * CartItemAdded con la cantidad incorporada en esta operación.
     */
    public function addItem(CartLine $line): void
    {
        $this->ensureOpen();

        $found = false;
        foreach ($this->lines as $index => $existing) {
            if ($existing->sku->equals($line->sku)) {
                $this->lines[$index] = $existing->withQty($existing->qty + $line->qty);
                $found = true;
                break;
            }
        }
        if (!$found) {
            $this->lines[] = $line;
        }
        $this->lines = array_values($this->lines);

        $this->recordEvent(new CartItemAdded(
            $this->id->toString(),
            $line->sku->value,
            $line->productName,
            $line->unitPriceCents,
            $line->qty,
        ));
    }

    /**
     * Fija la cantidad de un SKU. Cantidad 0 => remueve la línea. Reindexa las líneas restantes.
     */
    public function updateQty(Sku $sku, int $qty): void
    {
        $this->ensureOpen();

        if ($qty < 0) {
            throw CartException::because('La cantidad no puede ser negativa.');
        }
        if ($qty === 0) {
            $this->removeItem($sku);

            return;
        }

        $found = false;
        foreach ($this->lines as $index => $existing) {
            if ($existing->sku->equals($sku)) {
                $this->lines[$index] = $existing->withQty($qty);
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw CartException::because("El carrito no tiene una línea con el SKU {$sku->value}.");
        }
        $this->lines = array_values($this->lines);
    }

    public function removeItem(Sku $sku): void
    {
        $this->ensureOpen();

        $this->lines = array_values(array_filter(
            $this->lines,
            static fn (CartLine $line): bool => !$line->sku->equals($sku),
        ));
    }

    public function clear(): void
    {
        $this->ensureOpen();

        $this->lines = [];
    }

    /**
     * Fusiona las líneas de un carrito de invitado dentro de éste (suma cantidades por SKU). Graba
     * CartMerged. No modifica el carrito origen.
     */
    public function mergeFrom(Cart $guest): void
    {
        $this->ensureOpen();

        foreach ($guest->lines as $line) {
            $found = false;
            foreach ($this->lines as $index => $existing) {
                if ($existing->sku->equals($line->sku)) {
                    $this->lines[$index] = $existing->withQty($existing->qty + $line->qty);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->lines[] = $line;
            }
        }
        $this->lines = array_values($this->lines);

        $this->recordEvent(new CartMerged($this->id->toString(), $guest->id->toString()));
    }

    /**
     * Convierte el carrito en orden (Open → Converted). Idempotente: si ya está convertido, no hace
     * nada ni graba evento. Graba CartConverted (hecho que `orders` consumirá vía outbox).
     */
    public function markConverted(): void
    {
        if ($this->status === CartStatus::Converted) {
            return;
        }
        if ($this->status !== CartStatus::Open) {
            throw CartException::because('Solo un carrito abierto puede convertirse en orden.');
        }

        $this->status = CartStatus::Converted;
        $this->recordEvent(new CartConverted(
            $this->id->toString(),
            $this->customerId?->value,
            $this->sessionId,
            $this->currency,
            array_map(static fn (CartLine $line): array => $line->toArray(), $this->lines),
            $this->totalCents(),
        ));
    }

    public function totalCents(): int
    {
        return array_sum(array_map(static fn (CartLine $line): int => $line->subtotalCents(), $this->lines));
    }

    public function id(): CartId
    {
        return $this->id;
    }

    public function customerId(): ?Uuid
    {
        return $this->customerId;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function status(): CartStatus
    {
        return $this->status;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return list<CartLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Garantiza que el carrito admite cambios (está Open) antes de mutarlo.
     */
    private function ensureOpen(): void
    {
        if ($this->status !== CartStatus::Open) {
            throw CartException::because('El carrito no está abierto y no admite cambios.');
        }
    }
}
