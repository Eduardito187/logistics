<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Tipos de QR del ecosistema "todo es un QR" (ver docs/vision.md).
 *
 * Por ahora solo PKG tiene payload implementado; el resto se agrega cuando su flujo exista.
 */
enum LabelType: string
{
    /** Etiqueta de una unidad de producto del pedido. */
    case Package = 'PKG';

    /** Hoja de manifiesto o ruta: carga la ruta completa en la app. */
    case Manifest = 'MAN';

    /** Andén, puerta de tienda o estante: confirma dónde está físicamente el bulto. */
    case Node = 'NOD';

    /** Pegado en el camión: vincula chofer y vehículo. */
    case Vehicle = 'VEH';

    /** QR de entrega en el celular del cliente: prueba de entrega. */
    case Delivery = 'DLV';
}
