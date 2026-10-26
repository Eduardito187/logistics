---
name: product-labeling-rules
description: "Regla dura del dueño — nunca \"bulto\" (decir \"producto\"); nunca null/vacío; siempre mostrar nombre + SKU del producto"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-23T16:37:38.783Z
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
