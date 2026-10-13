# Fase 0 — Discovery: plan maestro

> Toolkit de la **Fase 0 (Discovery)** de la plataforma logística de Dismac.
> Antes de leer, tener presente `../vision.md` (secciones "KPIs del piloto" y "Fases")
> y `../roadmap.md` (sección "FASE 0 — Discovery", ítems `F0-*`).

## Objetivo

Llegar al MVP (Fase 1) con **línea base y materia prima real**, no con supuestos.
Discovery no escribe código de producto: produce **números, inventarios y accesos** que:

1. permiten **comparar** el antes y el después del piloto (KPIs), y
2. **alimentan** los bounded contexts ya construidos en Fase 1 (coverage, fleet, shipments, notifications).

Terminamos Discovery cuando cada uno de los cinco frentes de abajo cumple su criterio de "listo"
y el tablero de KPIs (`01-kpis.md`) tiene al menos **dos semanas de línea base** cargadas.

## Alcance

**Dentro:**

- Medir la línea base de los 5 KPIs del piloto **sin plataforma**, con el método que se usará luego.
- Relevar la calidad de las coordenadas y el mapa de cobertura actual.
- Inventariar la flota, la tripulación y los nodos reales.
- Relevar el formato real de las fuentes de pedidos (SAP, Magento, Excel manual).
- Conseguir accesos y datos de dispositivos (Firebase/FCM, impresoras Zebra, proveedor de SMS).

**Fuera:**

- Integrarse realmente con SAP o Magento (eso es Fase 1/2).
- Desarrollar endpoints, dominio o admin.
- Instalación y armado de productos (fuera del alcance del proyecto completo).

## Los cinco frentes

Cada frente tiene su documento de detalle en este mismo toolkit. Los enlaces son relativos;
algunos documentos pueden crearse después, pero el frente y su entregable se gobiernan desde aquí.

| Frente | Documento | Ítems del roadmap |
| --- | --- | --- |
| 1. KPIs y línea base | [01-kpis.md](01-kpis.md) | `F0-KPI-01..05` |
| 2. Fuentes de pedidos | [02-order-import.md](02-order-import.md) | `F0-SRC-01..04` |
| 3. Calidad de coordenadas y mapa | [03-coordinate-quality.md](03-coordinate-quality.md) | `F0-GEO-01..04` |
| 4. Flota, tripulación y nodos | [04-fleet-nodes.md](04-fleet-nodes.md) | `F0-FLEET-01..04` |
| 5. Satisfacción post-entrega | [05-satisfaction.md](05-satisfaction.md) | `F0-KPI-04` (instrumento) |
| 6. Accesos Firebase/Zebra/SMS | [06-access-checklist.md](06-access-checklist.md) | `F0-FIRE-01..04` |

> Nota: la satisfacción post-entrega es a la vez un KPI (frente 1) y un **instrumento** propio
> (frente 5), porque definir el canal, la pregunta y la escala es trabajo separado de medir.

### Frente 1 — KPIs y línea base

| | |
| --- | --- |
| **Qué se releva** | Los 5 KPIs del piloto: fórmula, fuente, frecuencia, línea base actual y meta de salida. |
| **Cómo** | Conteo manual / planillas del call center y de despacho; muestreo de llamadas de choferes; encuesta post-entrega piloto. Registro semanal en `templates/kpi-baseline.csv`. |
| **Responsable sugerido** | Jefe de operaciones + analista de datos; apoyo del supervisor del call center. |
| **Entregable** | `01-kpis.md` cerrado + CSV con ≥ 2 semanas de medición real. |
| **Listo cuando** | Cada KPI tiene fórmula, fuente y meta acordadas, y hay línea base medida y cargada. |

### Frente 2 — Fuentes de pedidos

| | |
| --- | --- |
| **Qué se releva** | Formato real de pedidos de SAP (campos, materiales, centro/almacén), export de Magento actual, y la plantilla de carga manual/Excel. Mapeo bulto↔material↔centro↔almacén. |
| **Cómo** | Pedir a TI un export de muestra de cada fuente; documentar campos en una tabla; diseñar la plantilla Excel con validaciones. |
| **Responsable sugerido** | Analista de integraciones / TI + referente de despacho. |
| **Entregable** | `02-order-import.md` + plantilla Excel de pedidos + diccionario de campos por fuente. |
| **Listo cuando** | Hay un ejemplo real por fuente y un formato propio de ingreso acordado (insumo de `shipments`). |

### Frente 3 — Calidad de coordenadas y mapa

| | |
| --- | --- |
| **Qué se releva** | Export de direcciones/coordenadas actuales con métricas de calidad (precisión, duplicados, nulos, fuera de zona); mapa de cobertura por departamento (KML/GeoJSON) con formato, capas y vigencia; fuentes de geocodificación y su costo/cuota; criterios del score de confianza de dirección. |
| **Cómo** | Export de la base actual; script de perfilado; catálogo de archivos de mapa; comparación de geocodificadores (Google vs interno). |
| **Responsable sugerido** | Analista de datos / GIS + TI. |
| **Entregable** | `03-coordinate-quality.md` + reporte de calidad + mapa catalogado + criterios de score. |
| **Listo cuando** | Se conoce el % de direcciones usables y existe el mapa en formato importable por `coverage`. |

### Frente 4 — Flota, tripulación y nodos

| | |
| --- | --- |
| **Qué se releva** | Inventario de vehículos (capacidad kg/m³, CD base, estado, documentos), choferes y ayudantes (licencias, vencimientos), catálogo de nodos (CDs, tiendas, puntos de recojo con direcciones y capacidades) y corredores entre departamentos con volúmenes aproximados. |
| **Cómo** | Planillas de operaciones + validación en terreno; georreferenciar nodos; estimar volúmenes por corredor del histórico de despacho. |
| **Responsable sugerido** | Jefe de flota + supervisores de CD. |
| **Entregable** | `04-fleet-nodes.md` + inventarios en planilla (vehículos, tripulación, nodos, corredores). |
| **Listo cuando** | Los datos cargan directamente como seeders de `fleet` y `network` sin rework. |

### Frente 5 — Satisfacción post-entrega

| | |
| --- | --- |
| **Qué se releva** | Canal (SMS/web/push/llamada), pregunta exacta, escala (p. ej. CSAT 1–5 o NPS) y cómo se captura y registra la respuesta. |
| **Cómo** | Diseñar el instrumento, pilotear con un lote pequeño de entregas, ajustar tasa de respuesta. |
| **Responsable sugerido** | Analista de experiencia del cliente + call center. |
| **Entregable** | `05-satisfaction.md` + instrumento acordado + primeras respuestas de prueba. |
| **Listo cuando** | El instrumento está validado y produce un número comparable semana a semana. |

### Frente 6 — Accesos Firebase / Zebra / SMS

| | |
| --- | --- |
| **Qué se releva** | Acceso al proyecto Firebase de la app de clientes (publicar en topics FCM); % de entregas cuyo cliente tiene la app con notificaciones activas; inventario de impresoras Zebra (modelos, firmware, BT/WiFi) y celulares de la operación; proveedor y costo/tope de SMS de respaldo. |
| **Cómo** | Gestión con TI/marketing (Firebase), reporte de la app de clientes (% con push), relevamiento físico de dispositivos, cotización de proveedores SMS. |
| **Responsable sugerido** | Líder de plataforma + TI + marketing (dueño de la app de clientes). |
| **Entregable** | `06-access-checklist.md` con cada acceso conseguido o bloqueado, responsable y fecha. |
| **Listo cuando** | Firebase accesible, dispositivos inventariados y proveedor de SMS elegido con tope mensual. |

## Cronograma sugerido (2–3 semanas)

| Semana | Foco | Hitos |
| --- | --- | --- |
| **Semana 1** | Arranque y accesos | Acordar fórmulas de KPI (frente 1) y diseñar instrumento de satisfacción (frente 5). Lanzar gestión de Firebase y cotización SMS (frente 6). Pedir exports de fuentes de pedidos (frente 2) y de coordenadas (frente 3). |
| **Semana 2** | Relevamiento y medición | Primera semana de línea base de KPIs cargada en el CSV. Perfilado de coordenadas y catálogo de mapa (frente 3). Inventario de flota/tripulación/nodos (frente 4). Diccionario de campos por fuente (frente 2). |
| **Semana 3** | Cierre | Segunda semana de línea base. Metas de salida acordadas por KPI. Inventario de dispositivos y proveedor de SMS cerrados (frente 6). Revisión de que todo cumple su criterio de "listo". |

> Si algún acceso externo (Firebase) se demora, el frente 6 puede extenderse una semana sin bloquear
> al resto; es el riesgo principal del cronograma (ver ADR 006).

## Cómo conecta cada insumo con la Fase 1 ya construida

Los contextos de Fase 1 ya existen en código (`[~]` en el roadmap). Discovery les da los **datos reales**
que necesitan para dejar de ser esqueleto:

| Insumo de Discovery | Alimenta a | Para qué |
| --- | --- | --- |
| Mapa de cobertura (KML/GeoJSON) por departamento | `coverage` | Importador `F0-GEO-02` → `P1-COV-04`; resolver punto→zona con datos reales. |
| Score de confianza de dirección (criterios) | `shipments` / `coverage` | `F0-GEO-04` → `P1-SHP-03`; decidir qué direcciones son usables. |
| Inventario de flota y tripulación | `fleet` | `F0-FLEET-01/02` → seeders de `P1-FLT-08`; asignación y checklist con datos reales. |
| Catálogo de nodos y corredores | `network` | `F0-FLEET-03/04` → seeders de `P1-NET-08`; nodos y tramos reales. |
| Formato de pedidos y plantilla Excel | `shipments` | `F0-SRC-*` → ingesta `P1-SHP-05/06`; normalizar al modelo propio. |
| Acceso Firebase + % con push activo | `notifications` | `F0-FIRE-01/02` → `P1-NOT-02/09`; publicar en topics FCM y dimensionar la cascada. |
| Inventario Zebra + SMS | `labels` / `notifications` | `F0-FIRE-03/04` → impresión ZPL (`P1-LBL-02`) y canal SMS de respaldo (`P1-NOT-05`). |
| Línea base de KPIs | Piloto (Workstream D) | `F0-KPI-*` → `P1-PIL-05`; medir el piloto contra la línea base. |

> Regla de oro: **ningún insumo de Discovery se da por cerrado hasta que carga en su contexto de Fase 1
> sin rework** (un inventario que no se puede sembrar no está listo).
