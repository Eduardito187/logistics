# 05 — Instrumento de satisfacción post-entrega

> Fase 0 (Discovery). Cubre **F0-KPI-04** (acordar instrumento de "satisfacción post-entrega":
> canal, pregunta, escala y captura) y aporta a **F0-KPI-01/02** como KPI 5 del piloto.
> Objetivo: dejar acordado el instrumento **antes** de automatizarlo, para poder medir la línea
> base manual con el mismo método que se usará luego.

## Qué medimos

La **satisfacción del cliente con la entrega**, no con el producto ni con la compra. Es el quinto KPI
del piloto (`vision.md`): mide la experiencia de recibir el pedido (puntualidad, trato del chofer,
estado de los bultos, visibilidad durante el camino).

- **Unidad de medida:** una encuesta por **entrega completada** (no por cliente ni por pedido).
- **Métrica principal:** promedio de satisfacción general (1–5) y **% de respuestas 4–5** (satisfechos).
- **Métrica de cobertura:** **tasa de respuesta** (encuestas respondidas / encuestas enviadas). En
  Fase 0 es también un dato de riesgo: dice cuánto podemos confiar en el número.

## Canal

Alineado con **ADR 006** (notificaciones sin WhatsApp), la encuesta viaja por los canales que ya
usamos y que cuestan casi cero. En orden de preferencia:

1. **Push de la app de clientes** (FCM, gratis) para clientes con la app instalada y notificaciones
   activas. El push abre una vista corta de encuesta dentro de la app.
2. **Enlace en la página de tracking** (web). Cuando la entrega pasa a *entregada*, la misma página
   pública de tracking muestra la encuesta (y, si el cliente activó web push, un aviso). Esto además
   refuerza el tracking como motivo para instalar la app.
3. **Email transaccional** (casi gratis) como respaldo cuando no hubo push ni interacción web.

No se usa SMS para la encuesta: el SMS queda reservado para respaldo crítico de ubicación (ADR 006),
no para medición. En **Fase 0** la captura puede ser manual (una llamada corta o un formulario web
simple con las mismas preguntas y escala) para obtener la línea base; lo importante es que las
preguntas y la escala sean **idénticas** a las que después disparará la plataforma.

## Momento de envío

- Se dispara cuando la entrega queda **confirmada como entregada** (evento de `execution`:
  prueba de entrega / POD registrada).
- Con un **retraso de ~30 minutos** tras la confirmación, para no interrumpir el momento de recibir
  el pedido y dar tiempo a revisar los bultos.
- **Una sola vez por entrega.** Un recordatorio único a las ~24 h solo si no respondió y solo por un
  canal más barato (web/email), nunca repetir push.
- **Ventana de validez:** la encuesta acepta respuestas hasta 72 h después; pasada la ventana, se
  cierra y cuenta como no respondida.

## Preguntas (escala 1–5 + una abierta)

Escala de 1 (muy insatisfecho) a 5 (muy satisfecho) para las cerradas. Mantener el set corto para no
castigar la tasa de respuesta.

1. **Satisfacción general con la entrega.** "¿Qué tan satisfecho quedaste con la entrega de tu
   pedido?" (1–5). → métrica principal (KPI 5).
2. **Puntualidad.** "¿Llegó dentro del horario que te avisamos?" (1–5).
3. **Estado y trato.** "¿Tu pedido llegó completo y en buen estado, y el personal fue amable?"
   (1–5).
4. **Pregunta abierta (opcional).** "¿Querés contarnos algo para mejorar?" (texto libre, opcional).

> La pregunta 2 se cruza luego con el KPI de "% entregas a tiempo" (fuente dura del sistema) para
> detectar brechas entre la puntualidad real y la percibida. La pregunta 3 se cruza con incidencias
> de bultos dañados/faltantes.

## Cómo se tabula

- Cada respuesta guarda: id de entrega, nodo de origen, departamento/zona, canal por el que se
  respondió, timestamp, los tres puntajes 1–5 y el texto abierto.
- **Indicadores que se reportan por período (semana/mes del piloto):**
  - Satisfacción general promedio (media de P1).
  - **% satisfechos** = respuestas con P1 ≥ 4 sobre el total de respondidas.
  - Promedios de P2 (puntualidad percibida) y P3 (estado/trato).
  - **Tasa de respuesta** = respondidas / enviadas.
- **Segmentación:** por nodo de origen, por departamento/zona de entrega, por canal y por chofer
  (para detectar focos buenos y malos).
- Los comentarios abiertos se etiquetan a mano por tema (demora, daño, trato, comunicación) para el
  análisis cualitativo.
- En Fase 0 la tabulación puede vivir en una planilla; en Fase 1 alimenta el tablero de KPIs
  (`F0-KPI-05`).

## Cómo lo disparará la plataforma en Fase 1

El instrumento acordado aquí se automatiza así (diseño, no implementado en Fase 0):

- `execution` emite el evento de **entrega confirmada** (POD). Como todo I/O externo, el disparo
  pasa por **outbox** (nunca HTTP síncrono en el request).
- `notifications` consume ese evento y, respetando la **cascada de ADR 006** (push → web push →
  email), publica el aviso de encuesta en el **topic del envío** (`shipment_<id>`) para la app y
  habilita el bloque de encuesta en la página de `tracking`.
- El formulario escribe la respuesta por la API interna; la captura queda ligada al id de entrega y
  al nodo de origen para la tabulación de arriba.
- Reglas configurables vía `settings` (versionadas): retraso de envío, ventana de validez, si se
  manda recordatorio y por qué canal. Así se puede ajustar sin tocar código.

## Entregable de F0-KPI-04

1. Este documento, con preguntas y escala **congeladas** (son contrato para la app y para la línea
   base).
2. Una medición de **línea base manual** con el mismo instrumento, para comparar contra el piloto
   (ver `F0-KPI-02`).
3. Metas numéricas de salida (p. ej. satisfacción ≥ X, % satisfechos ≥ Y, tasa de respuesta ≥ Z)
   acordadas en `F0-KPI-03`.
