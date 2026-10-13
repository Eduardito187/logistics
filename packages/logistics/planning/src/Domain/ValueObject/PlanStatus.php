<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Estado del plan de reparto a lo largo de su ciclo de vida dentro de este contexto.
 *
 * El flujo es lineal: nace en Draft (se le asignan envíos), pasa a Proposed cuando el motor de rutas
 * resuelve orden y ETA, y termina en Published cuando un humano lo confirma y queda disponible para la
 * ejecución. Este contexto NO ejecuta la ruta: solo la propone y la publica.
 */
enum PlanStatus: string
{
    /** Borrador: se están asignando envíos al camión; todavía sin secuencia ni ETA. */
    case Draft = 'DRAFT';

    /** Propuesto por el motor de rutas: con secuencia, ETA y plan de carga, pendiente de ajuste/confirmación. */
    case Proposed = 'PROPOSED';

    /** Publicado: confirmado por un humano y disponible para que la ejecución lo tome. */
    case Published = 'PUBLISHED';
}
