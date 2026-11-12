<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Crypto;

use Logistics\Labels\Domain\Contract\LabelSigner;
use Logistics\Labels\Domain\ValueObject\LabelPayload;
use Logistics\Labels\Domain\ValueObject\SignedLabel;

/**
 * Firmante ABIERTO: genera etiquetas SIN clave criptográfica. La etiqueta es un JSON abierto y legible
 * (id, pedido, producto/SKU, bulto, origen/destino); su autenticidad NO depende de una firma secreta sino
 * del REGISTRO y el control de tráfico: el `label_id` es un ULID emitido por el sistema y rastreado en toda
 * su vida (emisión → escaneos → entrega). Un id inventado no existe en el registro ni pertenece a un pedido,
 * así que no se puede replicar una etiqueta aunque el QR sea abierto.
 *
 * Decisión del dueño (crítica): una etiqueta impresa NUNCA se descarta por un problema de claves. Al no haber
 * clave, no hay forma de que un cambio/pérdida de clave invalide una etiqueta ya impresa.
 *
 * La firma del QR (`s`) viaja como un marcador no secreto (64 bytes en cero) para no cambiar el formato del
 * payload; la validación abierta la ignora y valida por el `label_id` contra el registro (ver resolución de
 * etiqueta por id). El `activeKeyId` (`k`) queda en 0: "sin clave".
 */
final readonly class OpenLabelSigner implements LabelSigner
{
    /** key_id "sin clave": 0 marca que la etiqueta es abierta (no firmada criptográficamente). */
    public const int OPEN_KEY_ID = 0;

    public function activeKeyId(): int
    {
        return self::OPEN_KEY_ID;
    }

    public function sign(LabelPayload $payload): SignedLabel
    {
        // Marcador de 64 bytes en cero: mantiene el formato del QR sin aportar secreto. La validez la da el
        // registro (label_id), no este valor.
        return SignedLabel::of($payload, str_repeat("\0", SignedLabel::SIGNATURE_BYTES));
    }
}
