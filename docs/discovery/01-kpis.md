# Fase 0 — KPIs del piloto: definición, línea base y metas

> Parte del toolkit de Discovery. Ver el plan maestro en [00-plan.md](00-plan.md).
> Define los 5 KPIs del piloto de `../vision.md` (sección "KPIs del piloto") y cubre los ítems
> `F0-KPI-01..05` del roadmap. La medición semanal se registra en
> [`templates/kpi-baseline.csv`](templates/kpi-baseline.csv).

## Cómo leer cada KPI

Cada KPI tiene:

- **Definición exacta** — qué cuenta y qué no.
- **Fórmula** — cómo se calcula el valor.
- **Fuente del dato** — de dónde sale hoy (línea base) y de dónde saldrá en Fase 1.
- **Frecuencia** — cada cuánto se mide.
- **Línea base actual (sin plataforma)** — cómo medirla hoy, con el mismo método que se usará después.
- **Meta de salida del piloto** — valor objetivo propuesto, **a validar** con operaciones.
- **Automatización en Fase 1** — cómo la plataforma captura el dato sin conteo manual.

> Las metas son propuestas razonables marcadas **(a validar)**. Se cierran en `F0-KPI-03` con el
> jefe de operaciones antes de iniciar el piloto. La dirección de mejora de los KPIs 1–4 es **bajar**;
> la del KPI 5 es **subir**.

---

## KPI 1 — Llamadas del chofer al cliente por ubicación

- **Definición exacta.** Cantidad de llamadas (o mensajes) que el chofer hace al cliente **para
  preguntar o confirmar dónde está la dirección** durante una entrega. No cuenta llamadas por otros
  motivos (reprogramar, avisar llegada si ya hay dato claro).
- **Fórmula.**
  `tasa = llamadas_por_ubicación / entregas_realizadas` (llamadas por entrega),
  reportar también el **%**: `% entregas con ≥ 1 llamada de ubicación`.
- **Fuente del dato.**
  - Línea base: muestreo declarado por los choferes (bitácora simple por ruta) + revisión de registros
    de llamadas del celular de la operación sobre una muestra de rutas.
  - Fase 1: la app muestra la ubicación georreferenciada y registra si el chofer usó el botón "pedir
    confirmación de ubicación"; se cuenta desde los eventos de `execution`/`tracking`.
- **Frecuencia.** Semanal.
- **Línea base actual (sin plataforma).** Durante 1–2 semanas, pedir a cada chofer que anote en una
  planilla por ruta cuántas veces llamó al cliente solo por ubicación; dividir por entregas de esa ruta.
- **Meta de salida del piloto.** Reducir la tasa en **≥ 50 %** respecto de la línea base **(a validar)**.
- **Automatización en Fase 1.** La coordenada validada y el mapa en la app reducen la necesidad de
  llamar; `tracking` y la confirmación de ubicación por push (notifications) sustituyen la llamada.

---

## KPI 2 — Consultas "¿dónde está mi pedido?" al call center

- **Definición exacta.** Cantidad de contactos entrantes (llamada, chat, correo) cuyo motivo es
  **consultar el estado o la ubicación de un pedido en curso**. No cuenta reclamos de otra naturaleza
  (producto, facturación).
- **Fórmula.**
  `tasa = consultas_estado / pedidos_despachados` (consultas por pedido),
  reportar también el **volumen absoluto semanal**.
- **Fuente del dato.**
  - Línea base: tipificación de contactos del call center (agregar/usar el motivo "estado de pedido").
  - Fase 1: contactos que **persisten** pese a la página pública de tracking + comparación con visitas
    a esa página (`tracking`).
- **Frecuencia.** Semanal.
- **Línea base actual (sin plataforma).** Configurar/usar la tipificación "¿dónde está mi pedido?" en
  el sistema del call center durante 1–2 semanas y dividir por pedidos despachados en el período.
- **Meta de salida del piloto.** Reducir la tasa en **≥ 60 %** respecto de la línea base **(a validar)**.
- **Automatización en Fase 1.** La **página pública de tracking por token** (`P1-TRK-04`) y las
  notificaciones proactivas (en-ruta, llegando, entregado) hacen innecesaria la consulta; el dato se
  deriva de la tipificación residual del call center + analítica de la página de tracking.

---

## KPI 3 — % de entregas a tiempo y fallidas, con motivo

- **Definición exacta.** Dos tasas sobre las entregas planificadas del período:
  **a tiempo** (entregadas dentro de la ventana prometida) y **fallidas** (no entregadas), estas
  últimas desglosadas por **motivo** (cliente ausente, dirección no encontrada, rechazo, zona no
  accesible, falta de tiempo de ruta, otros).
- **Fórmula.**
  `% a_tiempo = entregas_en_ventana / entregas_planificadas × 100`
  `% fallidas = entregas_fallidas / entregas_planificadas × 100`
  más la **distribución de fallidas por motivo** (cada motivo como % de las fallidas).
- **Fuente del dato.**
  - Línea base: planilla de despacho / hoja de ruta con hora de entrega y resultado por pedido.
  - Fase 1: eventos de POD y de entrega fallida con catálogo de motivos (`execution`), proyectados por
    `tracking`.
- **Frecuencia.** Semanal (y acumulado del piloto).
- **Línea base actual (sin plataforma).** Registrar por pedido, en la hoja de ruta, la ventana
  prometida, la hora real y el resultado (entregado / fallido + motivo); calcular las tasas semanalmente.
- **Meta de salida del piloto.** **≥ 90 %** a tiempo y **≤ 5 %** fallidas **(a validar)**; además,
  **100 %** de las fallidas con motivo registrado.
- **Automatización en Fase 1.** La **prueba de entrega (POD)** y el flujo de **entregas fallidas con
  catálogo de motivos** (`P1-EXE-06/07`) capturan hora, geocerca y motivo automáticamente; la ventana
  prometida sale de `planning` (ETA).

---

## KPI 4 — Bultos descargados por error o faltantes

- **Definición exacta.** Cantidad de **bultos** (unidades físicas) que se descargan en el punto
  equivocado, o que **faltan** respecto de lo que debía ir en el camión, por período.
- **Fórmula.**
  `tasa = bultos_con_error / bultos_despachados × 100`,
  donde `bultos_con_error = descargados_por_error + faltantes` (reportar los dos sumandos por separado).
- **Fuente del dato.**
  - Línea base: reporte manual de incidencias de carga/descarga del supervisor de CD y de los choferes.
  - Fase 1: diferencia entre bultos **cargados validados** y **descargados escaneados** por parada
    (`execution`, carga y descarga validadas por escaneo).
- **Frecuencia.** Semanal.
- **Línea base actual (sin plataforma).** Llevar una planilla de incidencias de bultos (error de
  descarga / faltante) durante 1–2 semanas sobre las rutas del piloto; dividir por bultos despachados.
- **Meta de salida del piloto.** **≤ 0,5 %** de bultos con error **(a validar)**.
- **Automatización en Fase 1.** La **carga validada** (`P1-EXE-03`) detecta faltantes/sobrantes al subir
  al camión y la **descarga guiada por parada con escaneo** (`P1-EXE-05`) detecta descargas en el punto
  equivocado; el KPI se calcula desde los eventos de escaneo inmutables.

---

## KPI 5 — Satisfacción post-entrega

- **Definición exacta.** Nivel de satisfacción declarado por el cliente **después de recibir** su
  pedido, medido con un instrumento único y constante (el instrumento se define en
  [05-satisfaction.md](05-satisfaction.md), ítem `F0-KPI-04`).
- **Fórmula.** Propuesta **CSAT**:
  `CSAT = respuestas_satisfechas (4–5) / respuestas_totales × 100`,
  reportar también la **tasa de respuesta** = `respuestas / encuestas_enviadas × 100`.
  (Alternativa a validar: NPS = `% promotores − % detractores`.)
- **Fuente del dato.**
  - Línea base: encuesta piloto post-entrega por el canal elegido (p. ej. SMS o web) sobre una muestra.
  - Fase 1: encuesta disparada automáticamente al registrar el POD, vía `notifications`.
- **Frecuencia.** Semanal (acumulando respuestas).
- **Línea base actual (sin plataforma).** Enviar manualmente la encuesta acordada a una muestra de
  clientes tras la entrega durante 1–2 semanas y calcular CSAT + tasa de respuesta.
- **Meta de salida del piloto.** **CSAT ≥ 85 %** con **tasa de respuesta ≥ 20 %** **(a validar)**.
- **Automatización en Fase 1.** El evento de **entrega (POD)** dispara la encuesta por el canal del
  cliente (push/web/email/SMS de respaldo) a través de la cascada de `notifications`; las respuestas
  se consolidan automáticamente.

---

## Tabla resumen

| # | KPI | Fórmula (resumen) | Dir. | Fuente Fase 1 | Meta de salida (a validar) |
| --- | --- | --- | --- | --- | --- |
| 1 | Llamadas chofer→cliente por ubicación | llamadas_ubicación / entregas | ↓ | `execution` / `tracking` | −50 % vs base |
| 2 | Consultas "¿dónde está mi pedido?" | consultas_estado / pedidos | ↓ | `tracking` (página pública) | −60 % vs base |
| 3 | % entregas a tiempo / fallidas + motivo | en_ventana / planificadas; fallidas / planificadas | ↑/↓ | `execution` (POD, fallidas) | ≥ 90 % a tiempo, ≤ 5 % fallidas |
| 4 | Bultos descargados por error / faltantes | bultos_error / despachados | ↓ | `execution` (carga/descarga validadas) | ≤ 0,5 % |
| 5 | Satisfacción post-entrega | satisfechas / respuestas (CSAT) | ↑ | `notifications` (encuesta tras POD) | CSAT ≥ 85 %, respuesta ≥ 20 % |

## Tablero de seguimiento (`F0-KPI-05`)

El seguimiento arranca **manual**: una fila por semana y KPI en
[`templates/kpi-baseline.csv`](templates/kpi-baseline.csv). Durante el piloto (`P1-PIL-05`) se compara
el `valor_actual` contra `valor_linea_base` y `meta`. Cuando los contextos de Fase 1 emiten eventos,
el tablero se automatiza (por ahora, carga manual semanal).
