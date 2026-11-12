# ADR 022 — Despacho sin stock como regla, flujo único por propiedad, SAP desacoplado y etiquetas permanentes

Fecha: 2026-11-12
Estado: aceptado
Contextos: `promise`, `execution`, `sap-connector`, `outbox`, `labels`, `settings`

## Contexto

La plataforma es de **despacho**. Los pedidos llegan por API desde POS y Magento, que ya validaron el stock
en su lado. SAP sí lleva registros y stock, pero eso es asunto de SAP, no nuestro. El dueño fijó cuatro
principios que se venían aplicando a medias:

- el stock aparecía como regla planeada (promesa de FBD según stock del seller, ATP en el carrito);
- la promesa sumaba días a FBD y dropship, y el dominio de ejecución tenía una rama dropship
  ("solo seguimiento" / "transportista tercero") que bloqueaba los escaneos propios;
- a SAP casi no se le avisaba nada, y lo poco que se enviaba podía hacerse sincrónico dentro de un request;
- revocar y re-emitir etiquetas, y un cambio de clave de firma, invalidaron etiquetas ya impresas.

## Decisión

### 1. El stock nunca es regla

Ningún flujo de pedido → despacho (ingesta, promesa, capacidad, planificación, etiquetas, ejecución,
tracking) consulta, reserva ni valida stock. Si la orden llegó por API, se despacha. El contexto `inventory`
queda para registro e informes (y para la tienda web propia, decisión aparte), nunca como candado del
despacho.

### 2. Un único flujo para todo pedido

OWNED (retail), FBD (marketplace) y DROPSHIP siguen exactamente el mismo flujo: misma promesa de fecha
(se eliminó el lead time por tipo de propiedad) y mismos pasos de ejecución con nuestros escaneos
(preparar → cargar → salir → entregar o fallar). Se eliminaron `DropshipHandling`, la guardia de escaneos
propios y las confirmaciones del seller. El tipo de propiedad queda solo como dato (reportes, liquidaciones).

### 3. SAP desacoplado: "avisamos, registramos y seguimos"

- Punto de extensión genérico en el outbox (`OutboxObserver`): observadores que se ejecutan por cada mensaje
  procesado, cada uno en su try/catch; nunca cambian el resultado del despacho normal, sus reintentos ni el
  dead-letter.
- El observador SAP (`SapNotificationRecorder`) registra en `sap_notifications` (idempotente por `event_id`)
  los eventos configurados y encola el envío en una **cola propia `sap`**.
- Envío: `POST {base_url}{events_path}` con `{event_id, event_name, occurred_at, aggregate_id, payload}` y
  `Idempotency-Key = event_id`, timeout corto y circuit breaker. Se guarda la respuesta cruda; **la respuesta
  de SAP nunca cambia el estado de nada nuestro**. Hasta 3 intentos con backoff; después queda `failed` y no se
  requiere ninguna acción manual.
- Interruptor real: con SAP deshabilitado (o sin URL base) el aviso queda `skipped` y no se llama.
- URL, ruta de eventos, eventos a avisar, intentos y timeout se configuran en el admin
  (Configuración → Integraciones → SAP).
- No hay HTTP sincrónico a SAP dentro de requests: "despachar traspasos" e "importar órdenes" se encolan (202).
- Todo evento de dominio nuevo queda elegible para el catálogo de eventos notificables.

### 4. Etiquetas globales, permanentes y abiertas

- Una etiqueta emitida nunca se revoca ni se re-emite con id nuevo; se reimprime la misma (mismo id y QR).
- Modo abierto por defecto (`LABELS_OPEN=true`): sin clave de firma (`k:0`). La validez la da el registro:
  el `label_id` existe y pertenece al pedido. `/app/labels/public-keys` expone `open` para que la app no
  verifique firmas de ningún QR (ni nuevos ni ya impresos).
- Si alguna vez se usa firma (`LABELS_OPEN=false`), el registro append-only de claves por `key_id` y la
  guardia anti-pisada impiden que un cambio de clave invalide etiquetas emitidas.

## Consecuencias

- Las fechas prometidas son iguales para cualquier tipo de propiedad; los sellers con demoras propias no se
  modelan en la promesa.
- Un dropship que realmente despacha el seller por su cuenta no tiene flujo propio: se registra igual que
  uno normal (decisión del dueño: priorizar un único flujo).
- SAP puede quedar sin enterarse de un evento si falla 3 veces; queda constancia en `sap_notifications`.
- Supera las partes de ADR 017 que hablaban de lead time por tipo de propiedad y de modos dropship sin
  escaneos propios, y lo de ADR 004 sobre revocación/reimpresión con id nuevo.
