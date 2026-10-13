<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Estado de una recepción en nodo. Se abre al anunciar el envío en el nodo destino, acumula los bultos
 * escaneados mientras está abierta, y se cierra al conciliar (faltantes/sobrantes). Reconciliada es
 * terminal: no se reciben más bultos.
 */
enum NodeReceptionStatus: string
{
    /** Abierta: recibiendo escaneos de bultos en el nodo. */
    case Open = 'OPEN';

    /** Conciliada: se comparó lo recibido contra lo esperado y se cerró. Terminal. */
    case Reconciled = 'RECONCILED';
}
