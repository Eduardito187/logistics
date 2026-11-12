<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

/**
 * Contacto del cliente de un envío, leído del lado lectura de shipments al proyectar la entrega. Ambos
 * campos ya vienen normalizados (email en minúsculas y con formato válido; teléfono sin espacios de más) o
 * en null. Es PII: solo viaja hacia el read model interno del seguimiento, nunca a vistas públicas.
 */
final readonly class CustomerContact
{
    public function __construct(
        public ?string $email,
        public ?string $phone,
    ) {}
}
