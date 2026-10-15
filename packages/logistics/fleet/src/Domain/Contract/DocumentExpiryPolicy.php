<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

/**
 * Política de alerta de vencimiento de documentos de flota.
 *
 * Define, en días, la ventana con la que se considera que un documento está "por vencer" y debe
 * alertarse (incluye a los ya vencidos). El valor es configurable (settings versionados) con un
 * respaldo por configuración; la resolución concreta vive en Infrastructure, para que el dominio y la
 * aplicación no sepan de dónde sale el número.
 */
interface DocumentExpiryPolicy
{
    /**
     * Ventana de alerta en días (>= 0). Un documento que vence dentro de esta cantidad de días —o que
     * ya venció— entra en la alerta.
     */
    public function alertWindowDays(): int;
}
