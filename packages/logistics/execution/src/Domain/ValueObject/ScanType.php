<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

/**
 * Tipo de escaneo registrado en terreno. Cada escaneo es un hecho inmutable (hora, GPS, usuario,
 * dispositivo); el tipo dice en qué etapa de la operación ocurrió.
 */
enum ScanType: string
{
    /** Preparación: escaneo de EAN → etiqueta → carril antes de cargar. */
    case Preparation = 'PREPARATION';

    /** Carga validada del bulto al vehículo. */
    case Load = 'LOAD';

    /** Descarga guiada del bulto (en nodo o destino). */
    case Unload = 'UNLOAD';

    /** Prueba de entrega: QR del cliente o foto en el punto de entrega. */
    case ProofOfDelivery = 'PROOF_OF_DELIVERY';

    /** Recepción del bulto en un nodo logístico. */
    case NodeReception = 'NODE_RECEPTION';
}
