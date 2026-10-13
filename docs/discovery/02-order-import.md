# 02 — Ingreso de pedidos (plantilla manual / Excel)

Parte del **toolkit de Fase 0 (Discovery)**. Cubre la pieza **F0-SRC-03** del roadmap
("Definir el formato de carga manual / Excel (plantilla) para pedidos") y es el insumo del
futuro **P1-SHP-06** ("Ingesta: carga manual/Excel con validación y reporte de errores").

El objetivo es tener, desde ahora y sin escribir código de producción, un formato tabular que la
operación pueda llenar en Excel y que mapee **1:1** a lo que la API de ingreso del contexto
`shipments` necesita (el comando `ReceiveShipment`). Así, cuando exista el comando
`logistics:shipments:receive-manual`, el mismo archivo ya validado se carga sin retrabajo.

## Archivos

- `templates/order-import-template.csv` — plantilla vacía: solo la cabecera. Se copia y se llena.
- `templates/order-import-example.csv` — ejemplo con datos ficticios bolivianos (La Paz, Santa Cruz,
  Cochabamba, El Alto), algunos con coordenadas y otros sin ellas, y un pedido con dos líneas.
- `../../tools/discovery/validate_order_import.php` — validador CLI (solo PHP estándar, sin Composer).

## Formato

- **Un renglón por línea de pedido** (un producto con su cantidad).
- Varias líneas del **mismo pedido** comparten el `order_external_id` (y deben repetir idénticos los
  datos de cabecera del pedido: fuente, cliente, dirección, fecha).
- Codificación UTF-8. Separador coma. Los textos con comas van entre comillas dobles (`"..."`).

### Columnas

| Columna             | Requerida | Descripción                                                                 | Mapea a (destino en `shipments`) |
|---------------------|-----------|-----------------------------------------------------------------------------|----------------------------------|
| `order_external_id` | Sí        | Id del pedido en su fuente de origen. Clave de agrupación de líneas.         | `SourceReference.externalId`     |
| `source`            | Sí        | Fuente del pedido: `sap`, `magento`, `store`, `manual` o `excel`.           | `OrderSource` + `SourceReference.source` |
| `customer_name`     | Sí        | Nombre del cliente que recibe. Máx. 180 caracteres.                         | `Shipment.customerName`          |
| `customer_phone`    | No        | Teléfono de contacto para coordinar la entrega.                             | — (capturado para el futuro; hoy `ReceiveShipment` no lo consume) |
| `address_text`      | Sí        | Dirección de entrega en texto libre, tal como la dicta el cliente.          | `Address.text`                   |
| `address_reference` | No        | Indicaciones adicionales (puntos de referencia, color de puerta, piso).     | `Address.reference`              |
| `lat`               | No        | Latitud en grados decimales (WGS84), rango -90..90. Ver regla de pares.     | `Address.geoPoint.lat`           |
| `lng`               | No        | Longitud en grados decimales (WGS84), rango -180..180. Ver regla de pares.  | `Address.geoPoint.lng`           |
| `delivery_date`     | No        | Fecha deseada de entrega (ISO `AAAA-MM-DD`).                                | — (capturado para el futuro; hoy `ReceiveShipment` no lo consume) |
| `sku`               | Sí        | Código del producto tal como lo manda la fuente (no es material SAP).       | `ShipmentLineInput.sku` → `Sku`  |
| `product_name`      | Sí        | Descripción legible del producto. Máx. 180 caracteres.                      | `ShipmentLineInput.productName` → `ProductName` |
| `quantity`          | Sí        | Cantidad de unidades del producto en la línea. Entero >= 1.                 | `ShipmentLineInput.quantity`     |
| `pieces_per_unit`   | Sí        | Piezas (cajas) en que viene cada unidad del producto. Entero >= 1.          | `Fraction` de pieza del `PhysicalUnit` (futuro; ver abajo) |

> **Regla de pares `lat`/`lng`:** o vienen las dos, o ninguna. Una sola de ellas es un error (el
> dominio construye el punto geográfico solo con el par completo).

## Cómo se mapea a `shipments`

El modelo real está en `packages/logistics/shipments/src/Domain/`. El mapeo es:

1. **Idempotencia — `order_external_id` + `source` → `SourceReference`.**
   El par (fuente, id externo) es la clave de idempotencia del ingreso: reingresar el mismo pedido de
   la misma fuente **no** crea un envío nuevo (índice único `(source, external_id)` en la base, y
   `ReceiveShipmentHandler` corta temprano si ya existe). Por eso todas las líneas de un pedido
   comparten el mismo `order_external_id` y la misma `source`.

2. **Cabecera del pedido → `Shipment` + `Address`.**
   `customer_name` va a `Shipment.customerName`; `address_text` y `address_reference` a `Address`;
   `lat`/`lng`, si están, a `Address.geoPoint`. La **confianza** de la dirección NO se pone en el CSV:
   la calcula el geocodificador al recibir (`AddressConfidenceScorer`). Un pedido sin coordenadas
   entra con baja confianza hasta que se geocodifica — de ahí que el ejemplo incluya casos sin
   `lat`/`lng` a propósito.

3. **Cada renglón → una `ShipmentLine`.**
   `sku`, `product_name` y `quantity` forman la línea. De cada línea el dominio genera **`quantity`
   bultos** (`PhysicalUnit`): un bulto por unidad de producto ("unidad N de M").

4. **`pieces_per_unit` → `Fraction` de pieza (camino futuro).**
   En el dominio, cada `PhysicalUnit` tiene una fracción de **unidad** ("unidad N de M") y una de
   **pieza** ("pieza N de M"), para detectar, por ejemplo, que se cargaron 2 de las 3 cajas de un
   mismo ropero. Hoy `ReceiveShipment` deja la pieza en `1/1` (no conoce el desglose en recepción).
   La columna `pieces_per_unit` **captura ese dato desde la fuente** para cuando el comando de ingreso
   soporte el desglose en piezas; por eso se valida ya (entero >= 1), aunque todavía no se consuma.

**Bultos estimados** que reporta el validador = suma de `quantity` de las líneas válidas (un bulto por
unidad). `pieces_per_unit` no multiplica bultos: describe piezas **dentro** de una misma unidad.

## El validador

Script CLI en PHP puro (solo `fgetcsv`, sin Composer ni Laravel). No crea envíos: solo valida el
archivo y reporta.

```bash
php tools/discovery/validate_order_import.php docs/discovery/templates/order-import-example.csv
```

### Qué valida

- **Cabecera** exacta y en orden (las 13 columnas de arriba); tolera el BOM de Excel.
- **Campos requeridos** no vacíos (ver columna "Requerida").
- **`source`** dentro de `{sap, magento, store, manual, excel}` (sin distinguir mayúsculas). Se
  corresponde con el enum `OrderSource`, que en el dominio guarda los valores en MAYÚSCULAS; el
  comando de carga los normaliza con `strtoupper()`.
- **`quantity`** y **`pieces_per_unit`**: enteros >= 1.
- **`lat`/`lng`**: opcionales, pero si vienen deben venir juntas, ser numéricas y estar en rango
  (-90..90 y -180..180, igual que el value object `GeoPoint`).
- **Consistencia por pedido**: todas las líneas de un mismo `order_external_id` deben repetir
  idénticos los datos de cabecera (fuente, cliente, dirección, fecha).

### Qué reporta

- Resumen: nº de **pedidos** (`order_external_id` únicos), nº de **líneas** y **bultos estimados**.
- Tabla de **errores por fila** (número de fila del CSV + descripción).
- **Código de salida**: `0` si está todo bien, `1` si hay errores de validación, `2` si hay error de
  uso (argumento faltante, archivo ilegible o cabecera inválida). Útil para encadenarlo en scripts.

## Camino futuro (de CSV validado → comando de ingreso)

1. La operación llena el CSV a partir de `order-import-template.csv`.
2. Se valida con `validate_order_import.php` hasta que el resultado sea `OK` (exit 0).
3. El futuro comando **`logistics:shipments:receive-manual <archivo.csv>`** (P1-SHP-06) leerá el mismo
   archivo, agrupará por `order_external_id`, armará un `ReceiveShipment` por pedido (con sus
   `ShipmentLineInput`) y lo despachará por la **API única de ingreso** (P1-SHP-05). Al ser idempotente
   por `(source, external_id)`, reejecutar la carga del mismo archivo es seguro.
4. Las columnas hoy "para el futuro" (`customer_phone`, `delivery_date`, `pieces_per_unit`) se irán
   conectando a medida que el dominio y el comando las soporten, sin cambiar el formato del archivo.
