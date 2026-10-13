# ADR 004 — Etiquetas QR con JSON compacto firmado Ed25519

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

Cada unidad de producto del pedido lleva una etiqueta que se escanea al preparar, cargar, descargar y
entregar. La app debe entender la etiqueta sin internet (camiones en zonas sin señal) y nadie debe
poder fabricar o editar una etiqueta.

## Decisión

- El QR contiene un JSON compacto con claves cortas y orden fijo:
  `{"v","t","k","id","o","ln","sku","u","pc","org","dst","ts","s"}` (~260 caracteres).
- `u` = unidad N de M del mismo producto; `pc` = pieza (caja) N de M de un producto.
- Sin datos personales: ni nombre, ni teléfono, ni dirección.
- Firma Ed25519 (libsodium en PHP, `cryptography` en Dart). El servidor firma; la app trae solo
  claves públicas y verifica offline.
- **La firma se calcula sobre un mensaje canónico**, no sobre el JSON:
  `LGL|v|t|k|id|o|ln|sku|u|pc|org|dst|ts`. Así ningún lenguaje depende de cómo otro serializa JSON.
- `k` identifica la clave: permite rotar sin invalidar etiquetas ya impresas.
- Un vector de prueba compartido (`label-test-vector.json`) existe idéntico en ambos repos; los tests de
  cada lado lo reproducen y verifican.

## Alternativas consideradas

### QR con solo un ID y consulta al servidor
- Pros: QR mínimo.
- Contras: sin internet la app no sabe qué es el bulto; el chofer queda ciego en ruta.

### Firmar los bytes del JSON
- Pros: más simple.
- Contras: frágil entre PHP y Dart (orden de claves, escapes, números).

### HMAC con secreto compartido
- Pros: firma más corta.
- Contras: el secreto viviría en cada celular; quien lo extraiga puede fabricar etiquetas.

## Consecuencias

### Positivas
- Validación offline y antifalsificación real.
- Detección de duplicados (mismo `id` en dos lugares) y revocación por reimpresión.

### Negativas / trade-offs aceptados
- La firma ocupa 86 caracteres del QR.
- Cambiar el formato exige subir `v` y actualizar los dos repos a la vez.
