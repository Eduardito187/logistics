<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\HelperAvailabilityChanged;
use Logistics\Fleet\Domain\Event\HelperRegistered;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\HelperId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Ayudante de la flota: personal que acompaña al chofer en la carga, descarga y entrega.
 *
 * No conduce (eso es del chofer), pero sí tiene documentos con vencimiento (carné de salud, EPP, etc.)
 * que la flota debe vigilar. Una tripulación referencia a sus ayudantes por id (ver Crew). El teléfono
 * es opcional porque no todo ayudante tiene uno registrado.
 */
final class Helper extends AggregateRoot
{
    /**
     * @param list<DocumentExpiry> $documents
     */
    private function __construct(
        private readonly HelperId $id,
        private readonly string $name,
        private readonly ?string $phone,
        private array $documents,
        private bool $active,
    ) {}

    /**
     * Registra un ayudante nuevo. Arranca activo salvo que se indique lo contrario.
     *
     * @param list<DocumentExpiry> $documents
     */
    public static function register(
        HelperId $id,
        string $name,
        ?string $phone = null,
        array $documents = [],
        bool $active = true,
    ): self {
        $helper = new self($id, $name, $phone, array_values($documents), $active);
        $helper->recordEvent(new HelperRegistered($id->toString(), $name, $phone, $active));

        return $helper;
    }

    /**
     * Reconstituye un ayudante desde persistencia, sin grabar eventos.
     *
     * @param list<DocumentExpiry> $documents
     */
    public static function reconstitute(
        HelperId $id,
        string $name,
        ?string $phone,
        array $documents,
        bool $active,
    ): self {
        return new self($id, $name, $phone, array_values($documents), $active);
    }

    /**
     * Cambia la disponibilidad. Idempotente: si ya está en ese estado, no hace nada ni graba evento.
     */
    public function changeAvailability(bool $active): void
    {
        if ($this->active === $active) {
            return;
        }
        $this->active = $active;
        $this->recordEvent(new HelperAvailabilityChanged($this->id->toString(), $active));
    }

    public function addDocument(DocumentExpiry $document): void
    {
        $this->documents[] = $document;
    }

    /**
     * Documentos del ayudante que vencen dentro de $days días contados desde $ref (incluye vencidos).
     *
     * @return list<DocumentExpiry>
     */
    public function documentsExpiringWithin(int $days, DateTimeImmutable $ref): array
    {
        return array_values(array_filter(
            $this->documents,
            static fn (DocumentExpiry $document): bool => $document->expiresWithin($days, $ref),
        ));
    }

    public function id(): HelperId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    /**
     * @return list<DocumentExpiry>
     */
    public function documents(): array
    {
        return $this->documents;
    }

    public function isActive(): bool
    {
        return $this->active;
    }
}
