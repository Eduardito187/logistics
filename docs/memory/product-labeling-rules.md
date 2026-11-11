---
name: product-labeling-rules
description: "Regla dura del dueño — nunca \"bulto\" (decir \"producto\"); nunca null/vacío; siempre mostrar nombre + SKU del producto"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-11-11T04:07:13.400Z
---

El dueño es TAJANTE con cómo se nombran y muestran los productos en TODA superficie visible (panel Filament,
contrato/pantallas de la app Flutter, emails, tracking, etiquetas):

1. **Nunca la palabra "bulto"** (ni "Bulto"/"bultos") en texto visible → usar **"producto"** (Product/Products
   en inglés de admin). Aplica a labels, títulos, mensajes, headers de tabla, placeholders, helper texts,
   lang/es.json, blades, email y tracking. (Los identificadores técnicos internos —tabla `shipment_units`,
   clase `PhysicalUnit`, columna `unit_id`— NO se renombran; solo el TEXTO visible.)
2. **Nunca mostrar `null`, vacío, "—", ni "x o y"** donde va un producto. SIEMPRE mostrar el **nombre del
   producto + su SKU** (p.ej. "Heladera · SKU-RD-1"), y si aplica la pieza, "(1 de 3)". Fallback si falta
   nombre → usar el SKU; si falta todo → "Producto" (nunca null).

**Por qué:** el operador/cliente necesita ver QUÉ producto es (nombre + SKU), no un UUID, no "bulto", no vacío.
Relacionado con [[no-uuid-in-admin]] (misma filosofía: mostrar el valor legible real).

**Dónde se materializa:** resolver `UnitNames` (execution) debe unir `shipment_units`→`shipment_lines` y
devolver "ProductName · SKU (pieza i/n)", nunca "—"; `RouteStopView.items` (pantalla "entrega en curso" del
chofer) ya lleva `{sku, name, quantity, image_url}` → garantizar name+sku no nulos; `GetShipmentUnits`
(tracking), `ShipmentLineView`, columnas Filament de líneas/unidades, y la app Flutter al renderizar items.

---

**SIMBOLOGÍA + ESCANEO de etiquetas (2026-11-10, implementado y verificado).** El sistema de etiquetas ya
era sofisticado; lo relevante:
- **Simbología configurable `qr | barcode | both`** (VO `LabelSymbology`, setting versionado
  `labels.symbology` vía `SettingsLabelSymbologyProvider`, default `both`). UI nueva: página Filament
  **`LabelFormatSettings`** (Configuración → «Formato de etiqueta», registrada en `->pages([])` del
  AdminPanelProvider, escribe el setting por `SetSettingCommand`). El preview
  (`resources/views/filament/labels/label-preview.blade.php`) AHORA respeta la simbología VIGENTE
  (`LabelSymbologyProvider->current(now)`): dibuja solo QR, solo Code128, o ambos — el `label_id` (código
  único) va SIEMPRE abajo. El ZPL ya respetaba la simbología al emitir (`LabelBatchEmitter`+`ZplLabelRenderer`).
- **Escaneo agnóstico**: Code128 = el `label_id` directo; QR = JSON firmado → `SignedLabel::fromQrContent()
  ->payload->id` = `label_id`. Ambos → `ResolveLabelUnit(label_id)` (read-side existente). **No cambió el
  contenido del QR ni `LabelPayload::canonical()`** → el contrato de firma con la app Flutter y el vector
  `label_test_vector.json` SIGUEN válidos (no subir `v` ni regenerar).
- **Scanner WEB**: página `ScanLabelPage` (Operaciones → «Escanear etiqueta») con `BarcodeDetector` NATIVO
  del navegador (lee qr_code+code_128, CERO dependencia/CDN) + respaldo de código a mano → endpoint
  `GET /cockpit/label/resolve?code=` (`LabelScanController`, office-only) → `ResolveLabelUnit`.
- **Reglas de ciclo YA EXISTÍAN** en el dominio (no hubo que crearlas): `Delivery.guardTransition()` (orden
  prep→load→pod, transición inválida lanza) + `isDuplicate(idempotencyKey)` (no doble escaneo, reintento
  offline seguro). **Unicidad + regenerar**: `DuplicateLabelException` + `revoked_at` + acción «Reprint
  labels» en `ListEmittedLabels` (revoca + reemite) y endpoint `POST .../reprint`.

**CONTROL TOTAL DE CICLO DE VIDA + IMPRESIÓN + BOTÓN HEADER (2026-11-11, verificado).**
- **Auditoría con ACTOR**: tabla nueva `label_events` (label_id, event generated|printed|revoked, actor,
  actor_name, meta jsonb, occurred_at — migración aditiva en labels). Servicio `App\Support\Cockpit\
  LabelLifecycle`: `record()` (append) + `timeline()` que UNE `label_events` + los ESCANEOS
  (`execution_scan_events` por `unit_id`, resuelto con `ResolveLabelUnit`, actor vía `UserNames`, etapa) +
  base (Generada de `labels_emitted.created_at`, Revocada de `revoked_at`). Sección **«Ciclo de vida»** en
  la ficha de la etiqueta (`label-lifecycle.blade.php`) = timeline con «quién + etapa + cuándo».
  OJO: `execution_scan_events.type` se guarda en MAYÚSCULA (PROOF_OF_DELIVERY…) → `strtolower()` al mapear.
- **Impresión gestionable**: `LabelPrintController` (`GET /cockpit/labels/print?ids=a,b&printer=normal|sticker`,
  office) renderiza **hoja imprimible** (`print-sheet.blade.php`, auto `window.print()`, respeta simbología)
  y **registra `printed` + actor + impresora**. En `EmittedLabelResource`: acción por fila **Print**
  (normal/pegatinas, abre en nueva pestaña), **bulk `Print selected`** (`->toolbarActions([BulkAction…])`,
  junta varias en una hoja), y filtro **TernaryFilter `printed`** (impresa/pendiente, vía
  `printedLabelIds()` = distinct en `label_events`). Filtro vigente/revocada ya existía.
- **Botón de ESCANEO en el header**: render hook `TOPBAR_END` `filament.cockpit.scan-trigger` (ícono mira →
  `ScanLabelPage::getUrl()`), al lado de la campana/búsqueda. Office-only.
