# logistics/labels — Etiquetas QR firmadas

## Responsabilidad

Payload compacto del QR, firma Ed25519 con rotación de claves, verificación, revocación, plantillas ZPL
y pre-emisión de etiquetas del día. Decisión completa en `docs/adr/004-etiquetas-qr-firmadas-ed25519.md`.

## No es responsable de

Imprimir: la app envía el ZPL a la impresora.

## Estado

| Pieza | Estado |
| --- | --- |
| `LabelPayload` (PKG), `Fraction`, mensaje canónico | Implementado |
| `SignedLabel`: JSON del QR ida y vuelta | Implementado |
| `SodiumLabelSigner` / `SodiumLabelVerifier` con rotación por `k` | Implementado |
| `php artisan logistics:labels:keygen` | Implementado |
| Vector de prueba compartido con la app | Implementado |
| Emisión ligada a envíos, revocación, duplicados | Pendiente (depende de shipments/execution) |
| Plantillas ZPL y pre-emisión offline | Pendiente |
| Payloads de MAN, NOD, VEH, DLV | Pendiente |

## Uso

```php
$payload = LabelPayload::forPackage(
    keyId: $signer->activeKeyId(),
    id: (string) Str::ulid(),
    orderNumber: 'DM-260012345',
    lineNumber: 3,
    sku: 'LG-GT32WPP',
    unit: Fraction::of(1, 2),
    piece: Fraction::single(),
    origin: 'CD-SCZ',
    destination: LabelPayload::CUSTOMER_DESTINATION,
    issuedAt: time(),
);

$qr = app(LabelSigner::class)->sign($payload)->toQrContent();

$result = app(LabelVerifier::class)->verify(SignedLabel::fromQrContent($qr)); // VerificationResult::Valid
```

## Configuración (`.env`, nunca en el repo)

| Variable | Qué es |
| --- | --- |
| `LABELS_ACTIVE_KEY_ID` | Id de la clave con la que se firman las etiquetas nuevas |
| `LABELS_SIGNING_KEY` | Clave privada Ed25519 activa (base64) |
| `LABELS_PREVIOUS_PUBLIC_KEYS` | Claves públicas anteriores aún aceptadas: `id:base64,id:base64` |

## Contrato con la app

`tests/Fixtures/label-test-vector.json` debe ser idéntico a
`logistics-app/test/fixtures/label_test_vector.json`. Si cambia el formato: subir `v`, regenerar con
`php packages/logistics/labels/tests/Support/generate-test-vector.php` y copiarlo a la app.
