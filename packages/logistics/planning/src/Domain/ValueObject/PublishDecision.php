<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Resultado de la PUERTA DE PUBLICACIÓN (PublishGate): si un plan propuesto puede publicarse solo, o si se
 * RETIENE para revisión humana con la lista de motivos legibles. Dato puro e inmutable.
 */
final readonly class PublishDecision
{
    /**
     * @param list<string> $reasons
     */
    private function __construct(
        public bool $publishable,
        public array $reasons,
    ) {}

    /**
     * El plan pasa el gate: se publica sin intervención.
     */
    public static function publish(): self
    {
        return new self(true, []);
    }

    /**
     * El plan NO pasa: queda propuesto y se alerta al planificador con los motivos.
     *
     * @param list<string> $reasons
     */
    public static function hold(array $reasons): self
    {
        return new self(false, array_values($reasons));
    }
}
