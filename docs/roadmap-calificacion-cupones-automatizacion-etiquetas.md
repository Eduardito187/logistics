# Roadmap — Calificación, cupones, automatización del despacho y etiquetas lite

Fecha: 2026-11-12 · Alcance: 6 módulos pedidos por el dueño · Estado: propuesta para aprobar.

Este documento desglosa, módulo por módulo, qué existe hoy en la base (con rutas de archivo), qué falta,
el diseño propuesto, las fases con entregables y criterios de aceptación, el esfuerzo y las decisiones
que necesita el dueño.

## Principios que todo el roadmap respeta (aprobados)

1. **El stock nunca es regla.** Somos despacho: si la orden llegó por API, POS/Magento ya validó el stock.
   Nada de lo que se construya puede bloquear, rechazar ni demorar un pedido por stock.
2. **Un único flujo para todo pedido**, sea retail (OWNED), marketplace (FBD) o dropship. El tipo de
   propiedad queda solo como dato.
3. **Sistemas externos desacoplados (SAP, email, Magento, POS):** se avisan "fire-and-forget" por el outbox
   transaccional. Registramos de nuestro lado, enviamos asíncrono, guardamos la respuesta y sus fallas
   jamás nos frenan. Todo evento de dominio nuevo queda elegible para el notificador SAP, configurable en
   el admin (Configuración → SAP).
4. **Etiquetas globales y permanentes:** nunca se revocan ni se re-emiten con id nuevo; se reimprime la
   misma. Abiertas por defecto: su validez la da el registro (el label_id existe y pertenece al pedido).
5. **Arquitectura:** DDD + CQRS + Clean Architecture, un package por bounded context, UUID v7, outbox, sin
   HTTP externo sincrónico en requests. Admin en inglés con `es.json`, sin UUIDs visibles, OpenAPI en el
   mismo cambio que el endpoint.

## Resumen de módulos

| # | Módulo | Dónde vive | Base existente | Esfuerzo estimado | Parte |
|---|---|---|---|---|---|
| 1 | Calificación de envío (cron horario + mail) | `tracking` (extensión) | Alta: encuestas, endpoint público y NPS ya existen; falta el envío | ~16 días | 1 |
| 2 | Cupones/vales por problemas de entrega | `compensation` (package nuevo) | Baja: `promotions` es de campañas, sin códigos de un uso | ~23 días | 1 |
| 3 | Programación automática de horas | `promise` (+ `capacity`) | Media: cupos y reserva de slot existen, falta la asignación automática | parte de ~65 días | 2 |
| 4 | Asignación automática al camión por medidas | `planning` + medidas en `catalog` | Media: VRP maduro, pero sin medidas reales de producto | parte de ~65 días | 2 |
| 5 | Armado automático de ruta y viaje | `planning` → `execution`/`fleetops`/`telemetry` | Media-alta: VRP + OSRM/VROOM + `AssembleDailyRoute`; falta encadenar | parte de ~65 días | 2 |
| 6 | Etiquetas lite/chicas | `labels` (perfiles de tamaño) | Media: un único formato fijo de ~100×76 mm | ~15–20 días + rollos y piloto | 3 |

## Bugs encontrados en la base actual (conviene arreglarlos antes, Ola 0)

- **Planificación mezcla días:** `EloquentPendingDeliveryProvider` ignora `delivery_date`; un plan de un día
  puede incluir envíos prometidos para otros días.
- **Peso/volumen ficticios:** se calculan como cantidad de bultos × defaults (10 kg / 0,05 m³); no hay medidas
  reales de producto.
- **Replanificación por incidentes inerte:** `planning_shipment_replans` se escribe pero nunca se lee.
- **Sin disparo automático:** `planning.plan_published` no tiene reactor y nada agenda el auto-plan nocturno.
- **Restricciones sin dueño:** `planning_shipment_requirements` / `planning_vehicle_restrictions` no tienen
  quien las escriba y se leen sin filtro.
- **Turnos ignorados al planificar:** solo se valida que exista tripulación ese día.
- **Calificación:** `tracking_shipments` no tiene `delivered_at`; el evento de entrega no lleva el email del
  cliente; `tracking.public_link_ttl_hours` está sembrado pero nadie lo aplica.
- **Cupones:** `RecordEvidenceValidation` no emite evento, así que un vale por daño no puede auto-aprobarse
  con evidencia validada.

## Orden recomendado de ejecución

1. **Ola 0 — Correcciones de base** (bugs de arriba). Bajo riesgo, desbloquea todo lo demás.
2. **Calificación de envío** (quick win: reutiliza casi todo lo existente).
3. **Etiquetas lite** en paralelo (independiente; necesita comprar rollos y una prueba física).
4. **Medidas de producto en `catalog`** (prerrequisito de la asignación por medidas y de elegir
   automáticamente el tamaño de etiqueta).
5. **Programación automática de horas → asignación al camión → ruta y viaje** (en ese orden; se encadenan).
6. **Cupones/vales** (requiere integrar el canje con Magento y POS; puede arrancar tras la calificación,
   que aporta el disparador "puntaje bajo").

## Dependencia transversal

Los módulos asumen el **notificador SAP genérico** (observador del outbox + tabla `sap_notifications` +
eventos a avisar configurables en el admin). Se está implementando en paralelo a este roadmap; cada evento
nuevo de estos módulos se agrega a su catálogo de eventos elegibles.

---

## Roadmap 01 — Calificación de envío y Cupones/Vales por problemas de entrega

> Estado: **propuesta para aprobación del dueño** · Fecha: 2026-11-12 · Rama de análisis: `feature/commerce-fase0`
> Alcance: dos módulos encadenados. El módulo 1 (calificación) **alimenta** al módulo 2 (vales) con la
> señal de "calificación baja"; el módulo 2 también se dispara por fallas operativas (fallida, tardía, daño).

### 0. Principios que este roadmap respeta (aprobados)

| # | Principio | Cómo se aplica aquí |
| --- | --- | --- |
| a | El stock nunca es regla | Ni la encuesta ni el vale validan stock. El vale es un **crédito comercial**, no una reposición. |
| b | Toda orden se trata igual sin importar la propiedad (OWNED/FBD/DROPSHIP) | Misma elegibilidad de encuesta y misma política de vales. La propiedad solo se **guarda como dimensión** de reporte; la imputación del costo a un seller es decisión abierta (§2.8). |
| c | Sistemas externos fire-and-forget por outbox | Email, Magento y SAP se notifican por outbox; si fallan, se reintenta/dead-letter y **nunca bloquea** el flujo. Todo evento nuevo queda **elegible para el notificador SAP**, activable en *Configuration*. |
| d | DDD + CQRS + Clean Architecture, UUID v7, outbox | Agregados, VOs `final readonly`, `Command`/`Query` por bus, ids UUID v7, admin en inglés con `es.json`, **sin UUIDs visibles**, `openapi/v1.yaml` en el mismo cambio que cada endpoint. |

Convenciones de dinero (heredadas de `commerce/promotions`): montos en **centavos enteros** (`int`),
porcentajes en **basis points** (10000 = 100 %), moneda `BOB`. Nunca `float` en el dominio.

---

## MÓDULO 1 — CALIFICACIÓN DE ENVÍO

### 1.1 Objetivo y reglas de negocio

**Objetivo:** medir la satisfacción post-entrega (KPI 5 del piloto, `docs/vision.md`) de forma proactiva:
un proceso **cada hora** invita por **email** a los clientes de envíos **ENTREGADOS** a calificar, y la
respuesta queda ligada a chofer, vehículo, ruta, nodo y propiedad para reportes y acciones.

Reglas:

1. **Elegibilidad de un envío** (todas deben cumplirse):
   - Estado visible `DELIVERED` en `tracking_shipments` (no `RETURNED`, `CANCELLED`, `FAILED`).
   - Entregado hace **≥ `delay_hours`** (default 2 h: que el cliente ya haya abierto la caja) y **≤ `window_hours`** (default 72 h: después ya no se invita).
   - **No tiene invitación previa** (una sola invitación por envío, garantizado por índice único).
   - **No tiene respuesta** ya registrada (pudo calificar desde la página `/t/{token}` antes del email).
   - El envío tiene `customer_email` con formato válido.
   - El email **no está dado de baja** (opt-out).
   - La fuente del envío no está excluida (configurable; p. ej. traspasos CD→tienda internos no se encuestan: solo entregas a cliente final).
2. **Unidad de calificación: el ENVÍO** (decisión recomendada). En multishipping una orden se reparte en
   envíos hermanos (`order_reference`) con chofer/vehículo/fecha distintos; calificar la orden completa
   mezclaría responsables. Para no saturar al cliente: **un solo email por orden y ventana**, que agrupa a
   todos los hermanos entregados elegibles (una tarjeta y un enlace por envío). Ver decisión abierta D1-1.
3. **Una respuesta por envío, inmutable** (regla ya vigente en el dominio `SatisfactionSurvey`).
4. **Qué se califica:**
   - Calificación general 1–5 (obligatoria).
   - Categorías opcionales 1–5: **Puntualidad**, **Estado del producto**, **Trato del chofer**.
   - Motivo cerrado cuando la general es ≤ 3 (`LATE`, `DAMAGED`, `INCOMPLETE`, `COURIER`, `OTHER` — ya existe `SurveyReason`).
   - Comentario libre ≤ 1000 caracteres (ya existe).
5. **Recordatorio:** como máximo **uno**, a las `reminder_after_hours` (default 48 h) si no respondió y el enlace sigue vigente. Configurable a 0 (sin recordatorio).
6. **Enlace público sin login:** token opaco por invitación, de un solo uso para responder, con vencimiento (`link_ttl_hours`, default 168 h = 7 días). Sin PII en la URL.
7. **Opt-out:** enlace "No quiero recibir encuestas" en cada email; efecto inmediato y permanente para encuestas (no afecta avisos transaccionales del pedido).
8. **Límites de envío (rate limiting):**
   - Máximo **1 email de encuesta por dirección cada `per_address_cooldown_hours`** (default 24 h) — los envíos que caen dentro se agrupan en el mismo email o se posponen a la siguiente corrida.
   - Tope por corrida `batch_size` (default 500) y tope por hora global `max_per_hour` (protege al sendmail del host y la reputación del dominio).
9. **Calificación baja** (general ≤ `low_score_threshold`, default 2, o *Estado del producto* ≤ 2): alerta al supervisor y **propone** un vale (módulo 2). Nunca emite un vale sin pasar por la política del módulo 2.

### 1.2 Estado actual (qué existe)

| Pieza | Ubicación | Estado |
| --- | --- | --- |
| Tabla de respuestas (1 por envío, rating, comment, reason, token) | `packages/logistics/tracking/database/migrations/2026_10_14_000100_create_tracking_satisfaction_surveys_table.php` | ✅ Reutilizable (extender aditivamente) |
| Agregado `SatisfactionSurvey` + VOs `Rating`, `SurveyId`, `SurveyReason` | `packages/logistics/tracking/src/Domain/Model/SatisfactionSurvey.php`, `.../Domain/ValueObject/` | ✅ Reutilizable (agregar categorías) |
| Evento `tracking.satisfaction_survey_recorded` (rating, comment, reason) | `.../Domain/Event/SatisfactionSurveyRecorded.php` | ✅ Ya viaja por outbox (aggregate `tracking.satisfaction_survey`) |
| Comando `RecordSatisfactionSurvey` (valida token, DELIVERED, unicidad) | `.../Application/Command/RecordSatisfactionSurvey/` | ✅ Reutilizable |
| Query `GetPublicSurveyState`, `GetSatisfactionSummary` + `SatisfactionSummaryView` (NPS, promedio) | `.../Application/Query/` | ✅ Reutilizable |
| Endpoint público `POST /t/{token}/survey` (throttle 20/min, 404/422/409) | `.../Presentation/Http/Controller/RecordSatisfactionSurveyController.php`, ruta en `TrackingServiceProvider::registerPublicPageRoute()` | ✅ Reutilizable |
| Formulario de estrellas en la página pública `/t/{token}` | `packages/logistics/tracking/resources/views/public.blade.php` (`$canSurvey`, `$surveyRecorded`) | ✅ Reutilizable como base visual |
| Admin: `SatisfactionSurveyResource` (lista/ver) + `SatisfactionStatsWidget` (NPS, promedio, respuestas) | `.../Presentation/Filament/Resources/SatisfactionSurveyResource.php`, `.../Widgets/SatisfactionStatsWidget.php` | ✅ Extender |
| Satisfacción por cliente en el Hub 360 | `app/Support/Cockpit/CustomerHub.php` (`satisfactionStats`) | ✅ (lee cross-context con `DB::table`; ver deuda C10) |
| Email transaccional por sendmail, cascada de canales, plantillas editables, log de notificaciones | `packages/logistics/notifications/src/Infrastructure/Channel/EmailChannelSender.php`, `MessageTemplateResource`, `MessageTypeRuleResource`, `NotificationResource` | ✅ Reutilizable |
| Mapeo evento → tipo de mensaje con idempotencia por (envío, tipo) | `.../Infrastructure/Dispatch/DomainEventNotificationDispatcher.php` | ✅ Patrón a replicar |
| Email del cliente | `shipments.customer_email` (`2026_10_13_000605_add_customer_contact_to_shipments_table.php`) | ✅ Dato existe |
| Agrupación multishipping | `shipments.order_reference`, `tracking_shipments.order_reference` | ✅ |
| Chofer/vehículo de la entrega | `execution_deliveries.vehicle_id`, `execution_driver_routes.driver_id/helper_ids`, `tracking_shipments.route_id` | ✅ (leer por puerto) |
| Setting `tracking.public_link_ttl_hours` (72) | `packages/logistics/settings/database/seeders/SettingsSeeder.php` | ⚠️ **Sembrado pero no se aplica en ningún código**: el token de tracking no vence |
| Scheduler | `routes/console.php` (outbox pump c/min, Magento c/5 min, alertas 07:00, etc.) | ✅ Agregar entrada |
| Configuración estilo Magento | `packages/logistics/settings/src/Presentation/Filament/Configuration/` (`ConfigurationSection`, `ConfigurationRegistry`; ejemplo `fleet/.../Configuration/DocumentAlertsSection.php`) | ✅ Agregar secciones |

### 1.3 Brechas

1. **No hay invitación proactiva**: nadie envía el email; hoy solo califica quien entra por su cuenta a `/t/{token}`.
2. **No hay `delivered_at`** en `tracking_shipments` (solo `updated_at`): no se puede filtrar "entregado en la ventana" con índice.
3. **El email no llega al notificador**: `DomainEventNotificationDispatcher` toma el email del *payload*; `execution.shipment_delivered` **no trae `customer_email`**, así que hoy el aviso `DELIVERED` no sale por email. Se necesita resolver el contacto por un puerto de lectura.
4. **No existe variable `{{tracking_url}}`/`{{survey_url}}`** en `ShipmentNotificationVariableProvider` (solo `order`, `customer`, `address`, `stops`, `eta`), aunque el doc de `MessageTemplate` la menciona.
5. **Solo hay una plantilla Blade de email** (`notifications::email.order`, pensada para el listado de productos); falta layout de encuesta con botones de estrellas y pie de baja.
6. **Sin opt-out**, sin rate limit por dirección, sin recordatorio, sin vencimiento del enlace.
7. **Sin categorías** (puntualidad / estado / trato) ni dimensiones desnormalizadas (chofer, vehículo, ruta, nodo, propiedad) en la respuesta → los reportes requerirían JOINs cross-context.
8. **Sin alerta de calificación baja** ni enganche con vales.
9. **El notificador SAP genérico no existe**: `SapMessageDispatcher` solo empuja `sap_connector.stock_transfer_enqueued`. La "elegibilidad para SAP" de eventos nuevos depende de una pieza transversal (ver §3 Dependencias).

### 1.4 Diseño propuesto

#### 1.4.1 Ubicación (bounded context)

**Extender `packages/logistics/tracking`** (namespace `Logistics\Tracking\`), sub-área *Feedback*. La
respuesta ya vive ahí y depende del token y estado visible de tracking; partir otro package hoy
duplicaría el puerto de estado. Se organiza en carpetas propias (`Domain/Model/SurveyInvitation.php`,
`Application/Command/SendSurveyInvitations/...`) para que, si el volumen lo pide, se extraiga a
`logistics/feedback` moviendo carpetas (decisión abierta D1-4).

#### 1.4.2 Modelo de dominio

| Elemento | Tipo | Responsabilidad |
| --- | --- | --- |
| `SurveyInvitation` | Agregado raíz (nuevo) | Ciclo de vida de la invitación de UN envío: `Pending → Sent → Reminded → Responded` o `→ Expired` / `→ Suppressed` (opt-out o sin email). Guarda `shipmentId`, `orderReference`, `tokenHash`, `expiresAt`, `sentAt`, `remindedAt`, `batchKey` (agrupa hermanos en un email) y el **snapshot de dimensiones**. |
| `SatisfactionSurvey` | Agregado raíz (existente, extendido) | Agrega `CategoryRatings` y `invitationId` opcional; canal de respuesta (`EMAIL_LINK` / `TRACKING_PAGE`). |
| `SurveyToken` | VO | 32 bytes CSPRNG base64url; se guarda **solo el hash SHA-256**; `matches()` en tiempo constante. |
| `CategoryRatings` | VO | `punctuality`, `productCondition`, `courier`: `?Rating` cada uno. |
| `DeliveryDimensions` | VO | Snapshot: `driverId`, `vehicleId`, `routeId`, `originNodeId`, `department`, `ownership`, `source`, `deliveredAt`, `promisedWindowEnd`. Primitivos, sin importar agregados ajenos. |
| `SurveyPolicy` | VO | `delayHours`, `windowHours`, `linkTtlHours`, `reminderAfterHours`, `perAddressCooldownHours`, `batchSize`, `maxPerHour`, `lowScoreThreshold`, `excludedSources`. Se arma desde settings (cachear forma array, regla 7). |
| `EmailOptOut` | Agregado pequeño | Hash SHA-256 del email normalizado (minúsculas, trim) + fecha + origen. Sin email en claro. |
| `InvitationEligibility` | Servicio de dominio puro | Decide elegible / motivo de exclusión a partir de primitivos (estado, fechas, email, opt-out). 100 % testeable unitario. |

#### 1.4.3 Eventos de dominio (todos elegibles para el notificador SAP)

| Evento (`eventName`) | Cuándo | Consumidores |
| --- | --- | --- |
| `tracking.survey_invitation_created` | El cron crea la invitación (incluye `batch_key`, `shipment_ids` del lote, `email` ya resuelto) | notifications (email `SURVEY_REQUEST`) |
| `tracking.survey_invitation_reminded` | Recordatorio | notifications (`SURVEY_REMINDER`) |
| `tracking.survey_invitation_expired` / `_suppressed` | Vence o se suprime | reportes (tasa de respuesta) |
| `tracking.satisfaction_survey_recorded` | Respuesta (existente; payload **extendido** con categorías y dimensiones) | reportes, compensation (módulo 2), SAP opcional |
| `tracking.low_rating_received` | Respuesta bajo umbral | notifications (supervisor, `SUPERVISOR_INCIDENT` existente), compensation |
| `tracking.survey_opted_out` | Baja | auditoría |

#### 1.4.4 Comandos y consultas (CQRS)

| Comando | Handler hace |
| --- | --- |
| `SendSurveyInvitations(now)` | Lee candidatos por el ReadModel, aplica `InvitationEligibility`, agrupa por `order_reference`+email, crea `SurveyInvitation` por envío, graba eventos al outbox. Idempotente (índice único `shipment_id`). |
| `SendSurveyReminders(now)` | Invitaciones `Sent` sin respuesta con `sentAt + reminderAfterHours ≤ now < expiresAt`. |
| `ExpireSurveyInvitations(now)` | Marca `Expired` las vencidas. |
| `RecordSatisfactionSurvey` (existente, extendido) | Acepta categorías e `invitationToken`; marca la invitación `Responded`; emite `low_rating_received` si aplica. |
| `OptOutOfSurveys(token)` | Registra opt-out por hash del email de la invitación; suprime invitaciones pendientes de esa dirección. |

| Consulta | ReadModel |
| --- | --- |
| `GetSurveyInvitationState(token)` | `SurveyInvitationStateView` (envíos del lote, productos con nombre + SKU, ya respondido, vencido) |
| `GetSatisfactionSummary` (existente, + filtros) | NPS, promedio, distribución 1–5, promedio por categoría, tasa de respuesta |
| `GetSatisfactionBreakdown(dimension, period)` | Por chofer / vehículo / ruta / nodo / departamento / propiedad / fuente |
| `ListLowRatings(period, status)` | Cola de atención para supervisores |

**Puertos de lectura cross-context** (Application/Contract, adaptador en Infrastructure, patrón de
`ShipmentNotificationVariableProvider`): `DeliveredShipmentCandidates` (tracking+shipments: email,
order_reference, fuente, propiedad), `DeliveryAssignmentReader` (execution: chofer/vehículo/ruta). Así el
dominio no conoce otras tablas y el cockpit deja de necesitar JOINs (ataca la deuda C10 de
`docs/roadmap-estructura-escalabilidad.md`).

#### 1.4.5 Tablas / migraciones (todas aditivas e idempotentes — nunca `migrate:fresh`)

```text
tracking_shipments            + delivered_at timestamptz null
                              + índice parcial (delivered_at) WHERE status = 'DELIVERED'
tracking_survey_invitations   id uuid pk (v7) · shipment_id uuid UNIQUE · order_reference varchar idx
                              batch_key varchar idx · token_hash char(64) UNIQUE · email_hash char(64) idx
                              status varchar idx · expires_at · sent_at · reminded_at · responded_at
                              driver_id uuid null · vehicle_id uuid null · route_id uuid null
                              origin_node_id uuid null · department varchar null · ownership varchar null
                              source varchar · delivered_at · promised_window_end null · timestamps
                              índice (status, sent_at) para recordatorios/expiración
tracking_satisfaction_surveys + invitation_id uuid null · channel varchar default 'TRACKING_PAGE'
                              + punctuality_rating / product_condition_rating / courier_rating smallint null
                              + driver_id / vehicle_id / route_id / ownership / department (desnormalizado)
                              + índices (driver_id, created_at), (vehicle_id, created_at)
tracking_survey_optouts       id uuid pk · email_hash char(64) UNIQUE · source varchar · created_at
```

Backfill de `delivered_at`: desde el `created_at` del mensaje `execution.shipment_delivered` en el
outbox (o `updated_at` como respaldo), en una migración de datos idempotente. `ProjectExecutionTracking`
pasa a setear `delivered_at` al proyectar la entrega.

#### 1.4.6 Endpoints (actualizar `openapi/v1.yaml` en el mismo cambio)

| Método y path | Puerta | Descripción |
| --- | --- | --- |
| `GET /r/{token}` | Web pública (sin login) | Página de calificación (Blade, CERO CDN, marca DisTrack). 404 genérico si no existe; pantalla "enlace vencido"/"ya calificaste". |
| `POST /r/{token}` | Web pública, `throttle:20,1` | Body: `ratings: [{shipment_ref, rating, punctuality?, product_condition?, courier?, reason?, comment?}]` (el lote puede traer varios envíos). 201 / 404 / 409 / 410 (vencido) / 422. |
| `POST /r/{token}/unsubscribe` (+ `GET` de confirmación) | Web pública | Opt-out. Cabecera `List-Unsubscribe` + `List-Unsubscribe-Post` en el email (RFC 8058). |
| `POST /t/{token}/survey` | Web pública | **Se mantiene** (compatibilidad); acepta categorías opcionales. |
| `GET /api/v1/integrations/feedback/surveys?from&to&cursor` | Integraciones | Export para BI (paginado por cursor sobre UUID v7). |
| `GET /api/v1/app/driver/ratings/summary` | App interna | Promedio propio del chofer (opcional, decisión D1-3). |

`shipment_ref` en la página es la referencia humana (`external_id`), nunca el UUID.

#### 1.4.7 Reactores del outbox

| Reactor (Infrastructure/Dispatch) | Escucha | Hace |
| --- | --- | --- |
| `SurveyInvitationNotificationDispatcher` (notifications) | `tracking.survey_invitation_created`, `_reminded` | `QueueNotification` con tipo `SURVEY_REQUEST`/`SURVEY_REMINDER`, email del payload; una notificación **por `batch_key`** (no por envío) para que el cliente reciba un solo email. Cascada: solo email (sin SMS: costo). |
| `LowRatingSupervisorDispatcher` (notifications) | `tracking.low_rating_received` | Aviso al supervisor (Telegram/email interno). |
| `LowRatingCompensationReactor` (compensation, módulo 2) | `tracking.low_rating_received` | Abre caso de compensación (propuesto). |
| `SapEventNotifier` (transversal) | Cualquier evento habilitado | Fire-and-forget a SAP. |

#### 1.4.8 Scheduler (`routes/console.php`)

```php
// Invitaciones a calificar la entrega: cada hora, sin solaparse, en un solo nodo.
Schedule::command('logistics:tracking:send-survey-invitations')->hourly()->onOneServer()->withoutOverlapping()->runInBackground();
// Recordatorios y expiración de invitaciones: cada hora, desfasado a :30 para no competir con el anterior.
Schedule::command('logistics:tracking:survey-housekeeping')->hourlyAt(30)->onOneServer()->withoutOverlapping()->runInBackground();
```

El comando solo despacha comandos por el bus; nunca envía email directo (lo hace el pump del outbox).

#### 1.4.9 Admin Filament (inglés + `es.json`, sin UUIDs, `Select::make(...)->native(false)` buscable)

- **Configuration › Customer experience › Delivery surveys** (nueva `ConfigurationSection` en
  `packages/logistics/tracking/src/Presentation/Filament/Configuration/DeliverySurveysSection.php`):
  *Enabled*, *Delay after delivery (hours)*, *Invitation window (hours)*, *Link lifetime (hours)*,
  *Reminder after (hours, 0 = off)*, *Per-address cooldown (hours)*, *Batch size*, *Max emails per hour*,
  *Low score threshold*, *Excluded sources* (multiselect buscable), *Send to SAP* (toggle por evento, si
  el notificador transversal existe). Escritura por `SetSetting` (versionado/auditable).
- **SatisfactionSurveyResource** (existente): columnas de categorías, chofer (nombre, vía `UserNames`),
  vehículo (placa, vía `VehiclePlates`), orden (`external_id`), canal; filtros por periodo, chofer,
  vehículo, nodo, propiedad, calificación; export CSV.
- **Survey invitations** (lista de solo lectura, colapsada en el hub del envío): estado, enviado, recordado, respondido, motivo de supresión.
- **Dashboards**: widget de tasa de respuesta; ranking de choferes y vehículos (mín. N respuestas para salir en ranking); tendencia semanal de NPS; mapa de calor por departamento. Secciones *Satisfaction* en los hubs de chofer, vehículo y cliente (skill `admin-entity-hub`).
- **Low ratings queue** en el cockpit (Control Tower): calificaciones bajas sin atender, con acción *Propose voucher* (abre módulo 2).

#### 1.4.10 Plantillas de email

Tipos nuevos en `MessageType`: `SURVEY_REQUEST`, `SURVEY_REMINDER`. Vista nueva
`notifications::email.survey`: saludo con `{{customer}}`, orden `{{order}}`, por cada envío del lote
la lista de productos (**nombre + SKU, nunca "bulto"**), cinco botones-estrella que enlazan a
`{{survey_url}}?r=N` (pre-selecciona la nota; la respuesta se confirma en la página, no por GET), y pie
con `{{unsubscribe_url}}`. Variables nuevas en el proveedor: `survey_url`, `unsubscribe_url`,
`survey_expires_at`. Asunto editable en `MessageTemplateResource` (ej.: "¿Cómo te fue con tu entrega del
pedido {{order}}?").

### 1.5 Fases y entregables — Módulo 1

#### Fase 0 — Cimientos de datos (S, 2 días)
- [ ] Migración aditiva `tracking_shipments.delivered_at` + índice parcial; `ProjectExecutionTracking` lo setea; backfill idempotente.
- [ ] Puerto `DeliveredShipmentCandidates` + adaptador Eloquent; puerto `DeliveryAssignmentReader`.
- [ ] Variable `{{tracking_url}}` en `ShipmentNotificationVariableProvider` (arregla brecha 4 de paso).
- **Aceptación:** envío entregado tiene `delivered_at`; consulta de candidatos usa el índice (`EXPLAIN`).
- **Pruebas:** Feature de proyección; unit del backfill; test de que el puerto ignora no-entregados.
- **Depende de:** nada.

#### Fase 1 — Invitación horaria por email (M, 4 días)
- [ ] Agregado `SurveyInvitation`, VOs `SurveyToken`, `SurveyPolicy`, servicio `InvitationEligibility`.
- [ ] Tabla `tracking_survey_invitations`; repositorio + mapper.
- [ ] Comando `SendSurveyInvitations` + artisan `logistics:tracking:send-survey-invitations` + scheduler.
- [ ] `MessageType::SurveyRequest`, dispatcher de notificaciones por `batch_key`, vista `email.survey`.
- [ ] Sección *Delivery surveys* en Configuration (subset: enabled, delay, window, batch, TTL).
- **Aceptación:** con 3 envíos hermanos entregados y 1 sin email, se crean 2 invitaciones + 1 suprimida, sale **1** email; re-ejecutar el cron no duplica nada.
- **Pruebas:** unit de elegibilidad (tabla de casos: ventana, sin email, ya respondido, fuente excluida); Feature del comando con outbox; test de idempotencia; test de que el email no incluye UUIDs.
- **Depende de:** Fase 0.

#### Fase 2 — Página pública con categorías y opt-out (M, 3 días)
- [ ] `GET/POST /r/{token}`, unsubscribe, cabeceras `List-Unsubscribe`.
- [ ] Extender `SatisfactionSurvey` con `CategoryRatings`, `invitationId`, `channel`; migración aditiva.
- [ ] `tracking_survey_optouts` + comando `OptOutOfSurveys`.
- [ ] openapi actualizado; `/t/{token}/survey` acepta categorías.
- **Aceptación:** token vencido → 410; segundo envío → 409; opt-out suprime invitaciones pendientes; página sin recursos externos (CSP self-only).
- **Pruebas:** Feature de endpoints (404/409/410/422/201), test de hash de token (no se guarda en claro), test de throttle.
- **Depende de:** Fase 1.

#### Fase 3 — Recordatorio, límites y dimensiones (S, 2 días)
- [ ] `SendSurveyReminders`, `ExpireSurveyInvitations`, comando housekeeping.
- [ ] Cooldown por dirección y `max_per_hour`; snapshot de `DeliveryDimensions` en invitación y respuesta.
- **Aceptación:** un solo recordatorio; misma dirección no recibe 2 emails en 24 h; respuesta trae chofer/vehículo.
- **Pruebas:** unit de cooldown; Feature con reloj fijo (`Clock`).

#### Fase 4 — Reportes y dashboards (M, 3 días)
- [ ] `GetSatisfactionBreakdown`, widgets, secciones en hubs, export CSV, endpoint de integraciones.
- **Aceptación:** ranking por chofer con nombres (sin UUIDs), filtros por periodo/departamento/propiedad; consultas < 300 ms con 1 M respuestas (índices compuestos).
- **Pruebas:** Feature de queries con datos sembrados; test de render de la página Filament (`withoutVite`).

#### Fase 5 — Calificación baja → alerta y vale (S, 2 días)
- [ ] Evento `tracking.low_rating_received`; aviso a supervisor; cola *Low ratings*; acción *Propose voucher*.
- **Aceptación:** nota 1 genera alerta y un caso de compensación **propuesto** (no emitido) en el módulo 2.
- **Depende de:** Módulo 2 Fase 1.

**Total módulo 1:** ~16 días de desarrollo (+ verificación real en containers por fase).

### 1.6 Riesgos y decisiones abiertas — Módulo 1

| Id | Decisión / riesgo | Recomendación |
| --- | --- | --- |
| D1-1 | ¿Encuesta por envío o por orden? | **Por envío**, email agrupado por orden. Alternativa: una nota por orden (más simple, pierde atribución al chofer). |
| D1-2 | ¿Se invita también en entregas fallidas (CSAT de la experiencia)? | No en esta etapa; la fallida ya dispara compensación por política. |
| D1-3 | ¿El chofer ve su propio promedio en la app? | Sí, agregado semanal sin comentarios (evitar represalias). Requiere aprobación de RRHH. |
| D1-4 | ¿Package propio `logistics/feedback`? | No ahora; carpetas separadas en tracking, extraíble después. |
| R1-1 | Reputación del dominio con sendmail nativo (spam) | SPF/DKIM/DMARC del dominio emisor, `max_per_hour`, List-Unsubscribe. Medir rebotes. |
| R1-2 | `tracking.public_link_ttl_hours` no se aplica hoy | Decidir si el token de tracking vence; la invitación tiene su propio TTL. |
| R1-3 | Emails faltantes o compartidos (B2B) | Medir % con email en Fase 0; email compartido → cooldown evita spam. |
| R1-4 | Ley de datos personales | No guardar email en claro fuera de `shipments`; opt-out por hash. |

---

## MÓDULO 2 — CUPONES / VALES POR PROBLEMAS DE ENTREGA

### 2.1 Objetivo y reglas de negocio

**Objetivo:** compensar al cliente cuando la entrega falla por causa nuestra, con un **vale coherente,
auditable y canjeable en Magento y POS**, para recuperar satisfacción sin regalar de más.

#### 2.1.1 Tipos y alcance

| Atributo | Valores |
| --- | --- |
| Método de cálculo | `PERCENTAGE` (bps sobre el valor afectado) o `FIXED` (centavos) |
| Alcance (base del cálculo) | `LINE` (una línea: `unit_price × cantidad afectada`), `SHIPMENT` (suma de líneas del envío), `ORDER` (suma de líneas de todos los envíos hermanos por `order_reference`) |
| Moneda | `BOB` únicamente. Línea con `unit_price_currency` ≠ BOB o nula ⇒ no hay emisión automática |
| Forma de canje | **Monto fijo** sobre la próxima compra (el porcentaje es solo el método de cálculo; se convierte a monto al emitir). Ver D2-1. |
| Uso | **Un solo uso**, nominativo (email del cliente), no acumulable con otro cupón, sin vuelto ni canje por efectivo |
| Vigencia | `validity_days` por política (default 90), fecha fin a las 23:59:59 hora Bolivia (UTC−4) |

#### 2.1.2 Reglas de coherencia (invariantes del dominio)

Sea `B` = valor afectado (centavos, según alcance), y por disparador `t` la política define
`value_t`, `max_pct_t` (tope porcentual), `abs_cap_t` (tope absoluto), `cumulative_pct` (tope acumulado por orden).

1. **Nominal:** `N = FIXED ? value_t : floor(B × value_t / 10000)`.
2. **Topes:** `E = min(N, floor(B × max_pct_t / 10000), abs_cap_t, B)` → **un vale nunca supera el valor afectado**.
3. **Tope acumulado:** `E = min(E, floor(B_order × cumulative_pct / 10000) − ya_emitido_orden)`; incluye vales `Issued`/`Redeemed`/propuestos aprobados (no rechazados/revocados). Evita apilar "tardía + calificación baja + daño".
4. **Redondeo:** siempre **hacia abajo**, al múltiplo de `rounding_step` (default 100 centavos = Bs 1). Nunca hacia arriba.
5. **Mínimo:** si `E < min_amount` (default Bs 10) no se emite; el caso queda como `NotCompensable` con motivo.
6. **Sin base no hay automático:** línea sin `unit_price` ⇒ solo emisión **manual**, con base declarada y justificación, y siempre requiere aprobación.
7. `max_pct_t ≤ 10000` validado en la política; `value_t ≥ 0`.
8. **Un vale automático por (disparador, envío)**: clave de idempotencia `trigger:shipment_id` (o `event_id` origen).

#### 2.1.3 Ejemplos de cálculo (Bs; política ilustrativa)

| # | Caso | Base B | Política | Cálculo | Vale |
| --- | --- | --- | --- | --- | --- |
| 1 | Producto de Bs 100 dañado; operador pide FIXED Bs 200 | 100,00 (LINE) | DAMAGED: max 50 %, cap 500 | min(200; 50; 500; 100) = 50 | **Bs 50** (nunca 200) |
| 2 | Línea 3 × Bs 349,90, 15 % por daño | 1 049,70 | DAMAGED: 15 %, max 50 % | floor(1 049,70 × 0,15) = 157,45 → redondeo Bs 1 | **Bs 157** |
| 3 | Orden Bs 2 450 llegó tarde, 10 % | 2 450,00 (ORDER) | LATE: 10 %, cap 150 | min(245; 150) | **Bs 150** |
| 4 | Orden Bs 500 ya tiene vale Bs 100; ahora calificación baja Bs 120 | 500,00 | cumulative 40 % = 200 | min(120; 200 − 100) | **Bs 100** |
| 5 | Envío hermano Bs 80 de una orden de Bs 1 200, fallida por nuestra causa, FIXED Bs 100 | 80,00 (SHIPMENT) | FAILED: cap 100, max 100 % | min(100; 80; 100; 80) | **Bs 80** |
| 6 | Accesorio Bs 25, 10 % | 25,00 | min_amount Bs 10 | 2,50 < 10 | **No se emite** |
| 7 | Línea sin precio | — | — | regla 6 | **Manual con aprobación** |

#### 2.1.4 Disparadores (qué problema genera vale)

| Disparador | Señal existente | Regla por defecto |
| --- | --- | --- |
| `DELIVERY_FAILED` | `execution.delivery_failed` con `FailureReason` | Solo motivos atribuibles a nosotros: `DAMAGED`, `OTHER` (manual). **No** `CUSTOMER_ABSENT`, `WRONG_ADDRESS`, `REJECTED` (configurable por motivo). Alcance SHIPMENT. |
| `LATE_DELIVERY` | `execution.shipment_delivered` (`created_at` del mensaje) vs `shipments.delivery_date + delivery_window_end` | Tarde si supera la ventana prometida + `late_tolerance_minutes` (default 60). Escalonado opcional: >1 h, >24 h. Alcance SHIPMENT. Excluye reprogramaciones pedidas por el cliente (`RescheduledOnReschedule` con motivo cliente). |
| `DAMAGED_PRODUCT` | Evidencia `EvidenceKind::Damage` validada (`RecordEvidenceValidation`), motivo `DAMAGED` en encuesta, o `returns` con disposición de daño | Alcance LINE (la línea dañada). Requiere evidencia o aprobación. |
| `INCOMPLETE` | Faltante en descarga (`RecordUnloadScan`/recepción) o motivo `INCOMPLETE` | Alcance LINE del faltante. Siempre aprobación. |
| `LOW_RATING` | `tracking.low_rating_received` (módulo 1) | Alcance SHIPMENT, valor bajo (ej. 5 %, cap Bs 50). Siempre **propuesto**, aprobación nivel 1. |
| `MANUAL` | Call center / supervisor desde el admin | Cualquier alcance; aprobación según monto. |

#### 2.1.5 Emisión y aprobación

| Monto efectivo E | Quién aprueba | Notas |
| --- | --- | --- |
| ≤ `auto_approve_max` (default Bs 50) y disparador automático con evidencia | **Automático** | Solo si la política del disparador tiene `auto_approve = true` |
| ≤ `level1_max` (default Bs 300) | Rol con permiso `compensation.approve.level1` (supervisor) | |
| > `level1_max` | Permiso `compensation.approve.level2` (gerencia) | |

- **Cuatro ojos:** quien propone no puede aprobar su propio caso.
- **SLA de aprobación:** caso pendiente > `approval_sla_hours` (default 24) se escala al nivel siguiente y se avisa.
- **Auditoría:** todo cambio de estado es un evento inmutable (quién, cuándo, por qué, cálculo completo con base, topes aplicados y política versionada). Actor como `varchar` (gotcha: `admin.id` es bigint).
- **Revocación:** solo antes del canje; propaga desactivación a Magento por outbox.

#### 2.1.6 Entrega al cliente y canje

- Email `VOUCHER_ISSUED`: código, monto en Bs, motivo en lenguaje de cliente, vencimiento, condiciones, productos afectados (nombre + SKU).
- Aviso opcional `VOUCHER_EXPIRING` 7 días antes.
- **Canje** en Magento (web) y POS (tiendas) — diseño en §2.4.5.

### 2.2 Estado actual (qué existe)

| Pieza | Ubicación | Qué sirve / qué no |
| --- | --- | --- |
| Motor de promociones (`PercentageOff`, `FixedAmountOff`, `FreeShipping`; `code` único; condiciones `min_subtotal_cents`, canal, ventana; `discountFor` topa al subtotal) | `packages/commerce/promotions/src/Domain/Model/Promotion.php`, `.../ValueObject/PromotionConditions.php`, migración `2026_10_29_000300_create_commerce_promotions_table.php` | ✅ Convenciones (centavos, bps, BOB) y la idea del tope. ❌ Es una **campaña**: sin uso único, sin límite de usos, sin titular, sin registro de canje, sin alcance por línea. |
| API de promociones: `POST /api/v1/channels/promotions/evaluate`; `GET/POST /api/v1/integrations/promotions[/{id}/activate|expire|rules]` | `routes/api/promotions.php` | ✅ Patrón de puertas. ❌ Ninguna orden/carrito consume promociones aún (no hay referencias en `cart`/`orders`). |
| Pagos con reembolso (`PaymentRefunded`, `OrderCancelledRefundDispatcher`) | `packages/commerce/payments` | Reembolso ≠ vale. Útil solo si un día se ofrece "reembolso en lugar de vale" (D2-5). |
| Órdenes (`OrderPaid`, `OrderCancelled`…) y facturación (`Invoice`, `OrderPaidInvoiceDispatcher`) | `packages/commerce/orders`, `packages/commerce/invoicing` | Son de dis-commerce (storefront futuro). Hoy los pedidos reales llegan de Magento/SAP como `shipments`. El descuento del vale lo factura Magento/POS, no nosotros. |
| Precio por línea | `shipment_lines.unit_price` decimal(12,2) + `unit_price_currency` (nullable) | ✅ Base del cálculo; ⚠️ nullable ⇒ regla 6. |
| Ventana prometida | `shipments.delivery_date`, `delivery_window_start/end` | ✅ Para `LATE_DELIVERY`. |
| Fallidas | `execution.delivery_failed` + `FailureReason` (`CUSTOMER_ABSENT`, `WRONG_ADDRESS`, `REJECTED`, `DAMAGED`, `OTHER`) | ✅ Disparador. |
| Inconvenientes en curso | `ReportDeliveryIssue`, `DeliveryIssueReported`, tabla `execution_delivery_issues` (`DELAY`, `ACCESS`, `CUSTOMER_REQUEST`, `ADDRESS_UNCLEAR`, `OTHER`) | ✅ Contexto para el caso (evidencia), **no** dispara vale (es descriptivo por diseño). |
| Evidencia de daño | `execution_delivery_evidence` (`EvidenceKind::Damage`, validación `pending/ok/review/mismatch`) | ✅ Disparador `DAMAGED_PRODUCT` cuando está validada. |
| Devoluciones | `packages/logistics/returns` (`ReturnOrder`, `ReturnDispositioned`) | ✅ Señal de daño complementaria. |
| Liquidación a sellers | `packages/logistics/settlement` (`SellerSettlement`) | Posible imputación futura del costo (D2-4); fuera de alcance por principio (b). |
| Lector Magento (solo **lectura** de pedidos) | `packages/logistics/shipments/src/Infrastructure/Magento/HttpMagentoOrderReader.php`, config `logistics-shipments.php` | ✅ Credenciales/base URL reutilizables. ❌ No hay cliente de **escritura** a Magento ni sección *Magento* en Configuration › Integrations. |
| Notificaciones email + plantillas | `packages/logistics/notifications` | ✅ Para `VOUCHER_ISSUED`. |

### 2.3 Brechas

1. No existe el concepto de **vale nominativo de un solo uso** ni su ciclo de vida (emitido, canjeado, vencido, revocado).
2. No existe **caso de compensación** con aprobación por niveles, cuatro ojos y auditoría.
3. No hay **calculadora de coherencia** (base por alcance, topes, acumulado, redondeo).
4. No hay **integración de canje**: ni push a Magento ni API de validación para POS.
5. No hay **detección de entrega tardía** (comparar entrega vs ventana prometida).
6. No hay **reportes de costo** de compensación por disparador, chofer, nodo, propiedad.
7. Notificador SAP genérico inexistente (dependencia transversal).

### 2.4 Diseño propuesto

#### 2.4.1 Ubicación

**Package nuevo `packages/logistics/compensation`** (namespace `Logistics\Compensation\`), registrado en
`bootstrap/providers.php` **y** en `extra.laravel.dont-discover` del `composer.json` raíz en el mismo
cambio (regla 3). Motivo: lo disparan hechos logísticos, vive fuera de Magento/SAP (ADR 002) y debe
servir a Magento, POS y al futuro dis-commerce. **No** se extiende `commerce/promotions` (campañas, otro
ciclo de vida); cuando dis-commerce tenga carrito, su checkout validará vales vía la consulta de
compensation (puerto `VoucherRedemption`), sin duplicar reglas. Usar skill `bounded-context`.

#### 2.4.2 Modelo de dominio

| Elemento | Tipo | Responsabilidad |
| --- | --- | --- |
| `CompensationCase` | Agregado raíz | El problema y la decisión: disparador, alcance, `AffectedValue` (snapshot de líneas: nombre, SKU, cantidad, precio), cálculo, estado `Proposed → Approved → Issued` / `Rejected` / `NotCompensable`, proponente, aprobador, nivel requerido, política aplicada (versión). |
| `Voucher` | Agregado raíz | El crédito: `VoucherCode`, `Money amount`, titular (email hash + email para envío), `expiresAt`, estado `Issued → Redeemed` / `Expired` / `Revoked`, `ChannelSync` (Magento: pending/synced/failed), canje (canal, referencia de la venta, fecha), `hold` temporal para POS. |
| `Money` | VO | Centavos `int` + `Currency` (solo BOB); `min`, `floorTo(step)`, sin floats. |
| `Percentage` | VO | bps 0..10000. |
| `CompensationScope` | VO enum | `LINE`, `SHIPMENT`, `ORDER`. |
| `AffectedValue` | VO | Base `B`, `B_order`, líneas afectadas con nombre+SKU; `fromLines()` rechaza moneda ≠ BOB o precio nulo. |
| `TriggerType` | VO enum | `DELIVERY_FAILED`, `LATE_DELIVERY`, `DAMAGED_PRODUCT`, `INCOMPLETE`, `LOW_RATING`, `MANUAL`. |
| `CompensationPolicy` | VO | Por disparador: `enabled`, `method`, `value`, `maxPct`, `absCap`, `autoApprove`, `validityDays`; globales: `cumulativePct`, `roundingStep`, `minAmount`, `autoApproveMax`, `level1Max`, `approvalSlaHours`, motivos de fallida compensables. Forma primitiva cacheable. |
| `VoucherCalculator` | Servicio de dominio puro | Aplica §2.1.2 y devuelve `CalculationTrace` (cada tope aplicado) para auditoría y para mostrar en el admin. |
| `ApprovalLevel` | VO | `AUTO`, `LEVEL1`, `LEVEL2` desde E y política. |
| `VoucherCode` | VO | `DT-XXXX-XXXX`, Crockford base32 (sin 0/O/1/I), 8 chars CSPRNG (~10¹² combinaciones), dígito de control; único por índice. En admin se muestra enmascarado `DT-••••-7K2Q`. |

#### 2.4.3 Eventos (todos elegibles para el notificador SAP; SAP los recibe como información contable, fire-and-forget)

`compensation.case_opened`, `compensation.case_approved`, `compensation.case_rejected`,
`compensation.case_escalated`, `compensation.voucher_issued`, `compensation.voucher_synced`,
`compensation.voucher_sync_failed`, `compensation.voucher_held`, `compensation.voucher_redeemed`,
`compensation.voucher_expired`, `compensation.voucher_revoked`. El payload lleva `order_reference`,
`external_id`, monto, disparador, ownership (dimensión) — nunca el código completo salvo en
`voucher_issued` hacia notifications/Magento.

#### 2.4.4 Comandos / consultas

| Comando | Notas |
| --- | --- |
| `OpenCompensationCase(trigger, scope, shipmentId, lines?, sourceEventId, proposedBy, manualBase?)` | Calcula con `VoucherCalculator`; decide nivel; si `AUTO` encadena aprobación. Idempotente por `sourceEventId`/`trigger:shipment`. |
| `ApproveCompensationCase(caseId, approver, overrideAmount?)` | Override solo **a la baja**; valida permiso y cuatro ojos. |
| `RejectCompensationCase(caseId, approver, reason)` | |
| `IssueVoucher(caseId)` | Genera código, emite `voucher_issued`. |
| `HoldVoucher(code, channel, saleRef, ttl)` / `RedeemVoucher(code, channel, saleRef, amountUsed)` / `ReleaseVoucherHold` | Para POS; transacción con `SELECT … FOR UPDATE` sobre el vale. Idempotentes por `saleRef`. |
| `RecordVoucherSync(voucherId, result)` | Lo llama el dispatcher Magento. |
| `RevokeVoucher(voucherId, actor, reason)` | Solo si no canjeado. |
| `ExpireVouchers(now)` / `EscalatePendingCases(now)` | Cron. |

Consultas: `ValidateVoucher(code, channel, email?)` → `VoucherValidityView` (válido, monto, vence,
motivo de rechazo); `ListPendingApprovals(level)`; `GetCompensationCase`; `GetCompensationCostReport(period, dimension)`;
`ListVouchersByCustomer`.

#### 2.4.5 Canje en Magento y POS — integración

**Recomendación: modelo híbrido con DisTrack como fuente de verdad.**

| Canal | Mecanismo | Detalle |
| --- | --- | --- |
| **Magento (web)** | **Push por outbox** (fire-and-forget) al emitir: crear *Cart Price Rule* de cupón específico vía REST (`POST /V1/salesRules` con `simple_action = cart_fixed`, `discount_amount = E`, `uses_per_coupon = 1`, `uses_per_customer = 1`, `to_date = expira`, `coupon_type = specific`) y el cupón (`POST /V1/coupons`). Guardar `rule_id` en `ChannelSync`. | Falla de Magento ⇒ `voucher_sync_failed`, backoff y dead-letter; el vale **existe igual** y es canjeable en POS. Revocación/expiración/canje en POS ⇒ push `PUT /V1/salesRules/{id}` (`is_active = false`). |
| **Detección de canje en Magento** | Pull horario (`GET /V1/coupons/search` con `times_used`) o webhook de un módulo Magento si existe | Marca `Redeemed` y desactiva para POS. |
| **POS (tiendas)** | **API de validación/canje** que el POS llama (POS → nosotros; no viola la regla de "sin HTTP síncrono saliente") | `POST /api/v1/integrations/vouchers/validate`, `/hold`, `/redeem`, `/release`. Credencial de servicio por tienda, rate limit, idempotencia por `sale_ref`. |
| **dis-commerce (futuro)** | Consulta interna por bus (`ValidateVoucher`) | Sin HTTP. |

Momento del email al cliente: se envía **cuando Magento confirma** (`voucher_synced`) o, si la sync no
confirma en `email_wait_minutes` (default 30), se envía igual indicando "canjeable en tiendas; web en
breve" (no bloqueamos por Magento — principio c). Ver D2-2.

Riesgo de doble canje entre canales: ventana de minutos entre canje POS y desactivación en Magento.
Mitigación: `hold` del POS desactiva en Magento por outbox inmediatamente; el monto es bajo; conciliación
horaria detecta y alerta duplicados (D2-3 si se exige cero riesgo: módulo Magento que valide contra nuestra API).

#### 2.4.6 Tablas (aditivas, UUID v7)

```text
compensation_cases      id · trigger idx · scope · shipment_id idx · order_reference idx
                        source_event_id UNIQUE null · idempotency_key UNIQUE
                        base_cents · order_base_cents · nominal_cents · effective_cents · currency
                        calculation_trace jsonb · policy_version int · approval_level
                        status idx · proposed_by varchar · approved_by varchar null · decided_at
                        reason text · affected_lines jsonb (nombre, sku, qty, unit_price)
                        ownership · driver_id null · vehicle_id null · origin_node_id null · timestamps
compensation_vouchers   id · case_id UNIQUE · code UNIQUE · amount_cents · currency
                        holder_email_hash idx · holder_email (para envío) · expires_at idx
                        status idx · magento_rule_id null · magento_sync_status · held_until null
                        held_by_channel null · redeemed_at · redeemed_channel · redeemed_sale_ref UNIQUE null
                        timestamps · índice parcial (expires_at) WHERE status = 'ISSUED'
compensation_case_log   id · case_id idx · voucher_id null · action · actor varchar · payload jsonb · created_at
                        (bitácora inmutable, solo INSERT)
```

#### 2.4.7 Endpoints (`openapi/v1.yaml`)

| Método y path | Puerta | Uso |
| --- | --- | --- |
| `POST /api/v1/integrations/vouchers/validate` | Integraciones (POS) | `{code, store_code, customer_email?}` → `{valid, amount, currency, expires_at, reason?}` |
| `POST /api/v1/integrations/vouchers/{code}/hold` | Integraciones | Reserva por `ttl` (default 10 min) para una venta |
| `POST /api/v1/integrations/vouchers/{code}/redeem` | Integraciones | `{sale_ref, amount_used}`; idempotente por `sale_ref` |
| `POST /api/v1/integrations/vouchers/{code}/release` | Integraciones | Libera el hold |
| `POST /api/v1/integrations/compensation/cases` | Integraciones (call center) | Abrir caso manual (202) |
| `GET /api/v1/integrations/compensation/cases?status&from&to` | Integraciones | Consulta/BI |
| `GET /api/v1/app/compensation/cases/{shipmentRef}` | App interna (supervisor) | Opcional |

Respuestas de error homogéneas (`ApiResponder`): `voucher_not_found` (404 genérico), `voucher_expired`,
`voucher_redeemed`, `voucher_held`, `voucher_revoked` (409/410).

#### 2.4.8 Reactores del outbox

| Reactor | Escucha | Hace |
| --- | --- | --- |
| `DeliveryFailedCompensationReactor` | `execution.delivery_failed` | Si el motivo es compensable por política ⇒ `OpenCompensationCase(DELIVERY_FAILED, SHIPMENT)` |
| `LateDeliveryCompensationReactor` | `execution.shipment_delivered` | Lee ventana prometida por puerto; si tarde ⇒ caso `LATE_DELIVERY` |
| `DamageEvidenceCompensationReactor` | `execution.delivery_evidence_captured` (kind `damage`) / `returns.return_dispositioned` | Caso `DAMAGED_PRODUCT` por línea, en estado propuesto hasta que la evidencia quede validada. **Brecha:** `RecordEvidenceValidation` hoy no emite evento; agregar `execution.delivery_evidence_validated` (aditivo) para aprobar automáticamente con evidencia `ok`. |
| `LowRatingCompensationReactor` | `tracking.low_rating_received` | Caso `LOW_RATING` (propuesto) |
| `MagentoVoucherSyncDispatcher` | `compensation.voucher_issued`, `_revoked`, `_expired`, `_redeemed` (canal POS) | REST a Magento; `RecordVoucherSync`; reintento/backoff |
| `VoucherNotificationDispatcher` (notifications) | `compensation.voucher_synced` o timeout | Email `VOUCHER_ISSUED` |
| `CompensationSupervisorDispatcher` (notifications) | `case_opened` con aprobación requerida, `case_escalated` | Aviso a aprobadores |
| `SapEventNotifier` (transversal) | Eventos habilitados | Fire-and-forget |

#### 2.4.9 Scheduler

```php
Schedule::command('logistics:compensation:expire-vouchers')->dailyAt('00:15')->onOneServer()->withoutOverlapping()->runInBackground();
Schedule::command('logistics:compensation:escalate-pending')->hourly()->onOneServer()->withoutOverlapping()->runInBackground();
Schedule::command('logistics:compensation:reconcile-magento')->hourlyAt(45)->onOneServer()->withoutOverlapping()->runInBackground();
Schedule::command('logistics:compensation:expiry-reminders')->dailyAt('09:00')->onOneServer()->withoutOverlapping()->runInBackground();
```

#### 2.4.10 Admin Filament

- **Configuration › Customer experience › Delivery compensation**: tabla por disparador (*Enabled*,
  *Method* `Select->native(false)`, *Value*, *Max % of affected value*, *Absolute cap (BOB)*,
  *Auto-approve*, *Validity (days)*), globales (*Cumulative cap per order (%)*, *Rounding step (BOB)*,
  *Minimum voucher (BOB)*, *Auto-approve up to (BOB)*, *Level 1 approval up to (BOB)*, *Approval SLA
  (hours)*, *Late tolerance (minutes)*, *Compensable failure reasons* multiselect). Validación: `max% ≤ 100`,
  `level1 ≥ auto`. Cada guardado versiona la política (`SetSetting`).
- **Configuration › Integrations › Magento** (nueva `IntegrationSection`): base URL, token (secreto
  cifrado), *Push vouchers* toggle, *Email wait for sync (minutes)*.
- **Compensation cases** (Resource): cola *Pending approval* con acciones *Approve* / *Approve lower
  amount* / *Reject* (visibles según permiso, ocultas al proponente); vista con la **traza de cálculo**
  legible ("Base Bs 1 049,70 · 15 % = Bs 157,45 · tope 50 % no aplica · redondeo Bs 1 → **Bs 157**"),
  orden por `external_id`, productos nombre + SKU, chofer/vehículo por nombre/placa. Acción *New
  compensation* (manual) con selector buscable de envío por referencia.
- **Vouchers** (Resource): código enmascarado, estado, Magento sync, canje; acciones *Resend email*,
  *Revoke*, *Retry Magento sync*.
- **Widgets/cockpit**: costo de compensación del mes vs entregas, por disparador, por chofer/nodo/propiedad,
  tasa de canje, vales por vencer. Sección *Compensation* en los hubs de envío, cliente y chofer.
- RBAC (contexto `access`): permisos `compensation.view`, `compensation.propose`,
  `compensation.approve.level1`, `compensation.approve.level2`, `compensation.revoke`, `compensation.configure`.

#### 2.4.11 Plantillas de email

`VOUCHER_ISSUED`: "Lamentamos el inconveniente con tu pedido {{order}}" + motivo cliente
(`{{voucher_reason}}`: "tu entrega llegó fuera del horario acordado"), `{{voucher_code}}`,
`{{voucher_amount}}` (formato `Bs 157,00`), `{{voucher_expires_at}}`, condiciones (un uso, no acumulable,
sin vuelto), dónde canjear (web y tiendas), productos afectados (nombre + SKU).
`VOUCHER_EXPIRING`: recordatorio 7 días antes. Vista `notifications::email.voucher`.

### 2.5 Fases y entregables — Módulo 2

#### Fase 0 — Política y calculadora pura (S, 2 días)
- [ ] Aprobación del dueño de la política inicial (valores de la tabla 2.1.4 y umbrales 2.1.5).
- [ ] Package `logistics/compensation` (esqueleto, providers + dont-discover), VOs `Money`, `Percentage`, `AffectedValue`, `CompensationPolicy`, servicio `VoucherCalculator` + `CalculationTrace`.
- **Aceptación:** los 7 ejemplos de §2.1.3 pasan como tests unitarios; imposible producir `E > B`.
- **Pruebas:** unit con data provider + test de propiedad (1 000 casos aleatorios: `0 ≤ E ≤ B`, `E` múltiplo del paso, acumulado respetado).

#### Fase 1 — Casos manuales con aprobación y auditoría (M, 4 días)
- [ ] `CompensationCase`, tablas `compensation_cases` + `compensation_case_log`, comandos Open/Approve/Reject, permisos RBAC.
- [ ] Resource *Compensation cases* + sección *Delivery compensation* en Configuration.
- **Aceptación:** supervisor no aprueba lo que propuso; > level1 exige level2; override solo a la baja; bitácora completa.
- **Pruebas:** Feature de comandos y permisos; render Filament; no se muestran UUIDs.
- **Depende de:** Fase 0.

#### Fase 2 — Vale, código y email (M, 3 días)
- [ ] `Voucher`, `VoucherCode`, `IssueVoucher`, `ExpireVouchers`, tipos de mensaje y vista email.
- **Aceptación:** caso aprobado ⇒ 1 vale con código único; email sale por outbox; vencido pasa a `Expired`.
- **Pruebas:** unicidad del código (colisión simulada), idempotencia de emisión, email con nombre + SKU.

#### Fase 3 — API de canje para POS (M, 3 días)
- [ ] Endpoints validate/hold/redeem/release, credencial por tienda, openapi.
- **Aceptación:** dos `redeem` concurrentes del mismo código ⇒ uno OK, otro 409; reintento con mismo `sale_ref` ⇒ 200 idempotente.
- **Pruebas:** Feature con concurrencia (dos conexiones), throttling, códigos inválidos ⇒ 404 genérico.
- **Depende de:** Fase 2; coordinación con el equipo POS (contrato).

#### Fase 4 — Sync con Magento (M–L, 4 días)
- [ ] Sección *Integrations › Magento*, cliente REST de escritura, `MagentoVoucherSyncDispatcher`, conciliación horaria.
- **Aceptación:** con Magento caído el vale se emite y es canjeable en POS; al volver, se sincroniza; canje POS desactiva en Magento.
- **Pruebas:** `Http::fake` (éxito, 5xx, timeout); test de que ningún request de usuario hace HTTP saliente.
- **Depende de:** Fase 2; acceso a Magento staging.

#### Fase 5 — Disparadores automáticos (M, 4 días)
- [ ] Reactores fallida, tardía, daño, calificación baja; puertos de lectura (líneas con precio, ventana prometida).
- **Aceptación:** fallida por `CUSTOMER_ABSENT` no genera caso; `DAMAGED` sí; tardía calculada contra ventana + tolerancia; reentrega del outbox no duplica casos.
- **Pruebas:** Feature por reactor con outbox; idempotencia por `event_id`.
- **Depende de:** Fase 1; Módulo 1 Fase 5 (para `LOW_RATING`).

#### Fase 6 — Reportes, escalamiento y SAP (S–M, 3 días)
- [ ] Widgets de costo, hubs, `EscalatePendingCases`, recordatorio de vencimiento, eventos habilitables para SAP.
- **Aceptación:** costo del mes por disparador y nodo coincide con la suma de vales; caso vencido de SLA se escala.

**Total módulo 2:** ~23 días de desarrollo.

### 2.6 Riesgos y decisiones abiertas — Módulo 2

| Id | Decisión / riesgo | Recomendación |
| --- | --- | --- |
| D2-1 | ¿Vale porcentual sobre la **próxima** compra o convertido a monto fijo? | **Monto fijo**: un % sobre la próxima compra puede superar el valor afectado y rompe la coherencia; Magento no topa nativamente un % por monto. |
| D2-2 | ¿Esperar la sync de Magento antes de enviar el email? | Esperar hasta 30 min, luego enviar igual (no bloquear). |
| D2-3 | ¿Tolerar ventana de doble canje web/POS? | Aceptar con conciliación al inicio; módulo Magento validador si el volumen lo justifica. |
| D2-4 | ¿Imputar el costo al seller en FBD/DROPSHIP vía `settlement`? | Principio (b): mismo trato al cliente; la imputación es decisión comercial posterior, se guarda `ownership` para poder hacerlo. |
| D2-5 | ¿Ofrecer reembolso en vez de vale? | Fuera de alcance; lo maneja el canal de venta. |
| D2-6 | ¿Vale nominativo (exigir email en el canje) o al portador? | Nominativo en POS (pedir email/CI); en Magento se limita por un uso. |
| D2-7 | Valores iniciales de la política (%, topes, umbrales) | Dueño + comercial + finanzas los fijan antes de Fase 1. |
| D2-8 | Tratamiento fiscal (SIN): descuento en factura del canal | Confirmar con contabilidad que el vale se factura como descuento en Magento/POS. |
| R2-1 | Abuso (clientes que fuerzan reclamos) | Tope acumulado por orden, mínimo, aprobación, reporte de clientes con más vales. |
| R2-2 | Precios nulos en líneas importadas | Medir % de líneas sin `unit_price`; exigir precio en la ingesta Magento/SAP. |

---

### 3. Dependencias transversales y orden sugerido

1. **Notificador SAP genérico** (fuera de estos módulos): un `SapEventNotifier` en `sap-connector` que
   lea una lista de eventos habilitados desde *Configuration › Integrations › SAP › Event notifications*
   y los empuje fire-and-forget. Los eventos de ambos módulos se declaran elegibles; mientras no exista,
   simplemente no se envían (no bloquea nada).
2. **Email operativo**: SPF/DKIM del dominio emisor antes de prender el cron en producción.
3. **Contrato POS**: definir con el equipo de tiendas el uso de `validate/hold/redeem`.
4. **Acceso a Magento** (staging) con token de integración para reglas de carrito.

Orden: M1-F0 → M1-F1 → M2-F0 → M1-F2 → M2-F1 → M1-F3 → M2-F2 → M2-F3 → M1-F4 → M2-F4 → M1-F5 + M2-F5 → M2-F6.

### 4. Definición de terminado (cada fase)

- [ ] Pest verde (reportar tests y aserciones), PHPStan nivel 8 limpio, Pint limpio.
- [ ] Verificación real contra los containers (comando del cron ejecutado, email visible con `MAIL_MAILER=log`, endpoint probado).
- [ ] `openapi/v1.yaml` actualizado en el mismo cambio; textos admin en inglés + `es.json`; sin UUIDs visibles; `Select` con `native(false)`.
- [ ] Migraciones aditivas e idempotentes; funciona en un docker nuevo con defaults (sin depender del `.env` local).
- [ ] Tests sobre `logistics_test` (nunca la base de desarrollo).

---

## Roadmap 02 — Programación automática de horas, asignación automática al camión y armado automático de ruta y viaje

> Sección del roadmap de producto. Estado del código relevado el 2026-11-12 sobre la rama
> `feature/commerce-fase0`. Lectura previa: `CLAUDE.md`, `docs/vision.md`, `docs/architecture.md`,
> ADR 001/002/008/009 (ruteo OSM-OSRM-VRP)/011/012/013/017/019/020.
>
> Convención de esfuerzo: **d** = día-persona de desarrollo con DoD completo (Pest + PHPStan 8 + Pint +
> verificación real en containers + OpenAPI + es.json).

---

### 0. Principios que este roadmap respeta (aprobados por el dueño)

| # | Principio | Cómo se materializa acá |
|---|-----------|-------------------------|
| a | **El stock nunca es regla.** Se despacha lo que POS/Magento ya validó. | Ningún módulo consulta `inventory` para decidir slot, camión o ruta. La falta de medidas de producto **nunca** bloquea; se usa un default y se marca para completar datos. |
| b | **Todo pedido se trata igual** sin importar el dueño (OWNED/FBD/DROPSHIP). | `Ownership` no entra en el scoring de slot/asignación/ruta. Solo cambia el **origen** (nodo de pickup) cuando el producto sale de otro CD; DROPSHIP que no pasa por flota propia queda fuera del VRP por *canal de entrega*, no por dueño. |
| c | **Sistemas externos fire-and-forget vía outbox.** | Cada evento nuevo de esta sección queda **elegible** para el notificador SAP configurable en Configuración (Magento-style). Un fallo de SAP/FCM/SMS nunca bloquea slotting, asignación ni ruta. |
| d | **DDD + CQRS + Clean, un package por contexto, UUID v7, outbox, sin HTTP externo síncrono en request.** | OSRM/VROOM se llaman solo desde jobs/colas (ya es así en `planning`). Admin en inglés + `es.json`, nunca UUIDs visibles, OpenAPI en el mismo cambio. |
| e | **Regla geográfica:** una ruta a clientes queda dentro de **un departamento**; paradas ordenadas por **cercanía**; CD→CD exento. | Ya es invariante en `PackingBin` / `RouteDepartmentPolicy`; los tres módulos la heredan sin reimplementarla. |

---

### 1. Fotografía del estado actual (lo que YA existe)

#### 1.1 Capacidad y calendario de despacho — `packages/logistics/capacity`

| Pieza | Estado | Archivo |
|---|---|---|
| Cupos por bucket (municipio \| zona \| nodo) × fecha × franja × unidad | **Funciona** | `src/Domain/Model/CapacityBucket.php`, `Domain/ValueObject/BucketScope.php`, `TimeSlot.php` ('HH:MM-HH:MM') |
| Unidades de cupo: `deliveries`, `weight`, `volume`, `appointments` | **Existe el enum**; en la práctica el consumo de promise es **solo `deliveries`** (1 entrega por reserva). `weight/volume` solo se cargan desde la UI. | `Domain/ValueObject/CapacityUnit.php`, `Presentation/Filament/Pages/DispatchCalendar.php` |
| Reglas 7 días, ventanas por día de semana, corte, feriados, horizonte | **Funciona** (versionado en settings por municipio) | `Domain/ValueObject/CapacityRuleset.php`, `Infrastructure/Settings/SettingsCapacityRulesetProvider.php` |
| Feriados/bloqueos por municipio o global | **Funciona** | `Domain/Model/DispatchBlackoutDay.php`, comandos `BlockDispatchDay`/`UnblockDispatchDay`, `DispatchDayAvailability` |
| Activación de cupos por rango (asíncrona, por lotes de 14 días) | **Funciona** | `Application/Command/ActivateCapacityWindow/*`, `Infrastructure/Queue/ActivateCapacityWindowJob.php`, agregado `ActiveHorizon` |
| Consumo/liberación atómicos (`SELECT … FOR UPDATE`), idempotentes | **Funciona** | `ConsumeCapacity`, `ReleaseCapacity` |
| Pantalla "Dispatch calendar" (semana, total/usado/disponible, bloqueos) | **Funciona** | `Presentation/Filament/Pages/DispatchCalendar.php` |

#### 1.2 Promesa y reserva de franja — `packages/logistics/promise`

| Pieza | Estado | Archivo |
|---|---|---|
| Calcular promesa / listar slots disponibles por municipio | **Funciona** | `Application/Query/CalculateDeliveryPromise`, `ListMunicipalityPromise`, `ListAvailableSlots` |
| Reserva de franja **día + ventana elegidos por el cliente** (atómica: consume cupo + fija franja en el envío) | **Funciona** | `Application/Command/ReserveMunicipalitySlot/ReserveMunicipalitySlotHandler.php`, `Infrastructure/Shipments/ShipmentsOrderDeliverySlotGateway.php` |
| Ciclo Reserved → Confirmed (al pagar) / Expired / Released (al cancelar) | **Funciona** | `OrderPaidCupoConfirmDispatcher`, `OrderCancelledCupoReleaseDispatcher`, `ExpireDueReservations` (scheduler) |
| Gate de días bloqueados / fuera de horizonte | **Funciona** (gotcha: caché 60 s en `CachingAvailabilityProvider`) | `Domain/Service/DispatchDayGate.php` |
| **Asignar la ventana automáticamente cuando el cliente solo eligió FECHA** | **No existe**. Hoy el canal debe mandar `window_start/window_end`. | — |
| **ETA precisa comunicada al cliente** | **No existe** como dato de promesa (la ETA vive solo en el plan). | — |

#### 1.3 Envíos — `packages/logistics/shipments`

- `shipments.delivery_date`, `delivery_window_start`, `delivery_window_end` (migración
  `database/migrations/2026_10_13_000606_add_delivery_slot_to_shipments_table.php`, índice `(delivery_date, status)`).
- Comandos `SetDeliverySlot` (evento `shipments.shipment_delivery_slot_assigned`) y
  `RescheduleShipmentDelivery` (evento `shipments.shipment_rescheduled` → tracking «reprogramada» + aviso).
- **`shipment_lines` NO lleva medidas**: solo `sku`, `product_name`, `quantity` (+ imagen/precio). `shipment_units`
  tampoco (índices de bulto/pieza). **No hay peso ni dimensiones en ningún lugar del envío.**

#### 1.4 Catálogo — `packages/commerce/catalog`

- `commerce_products`: `name`, `canonical_sku`, `category_id`, `variants` (jsonb), `media` (jsonb). **Sin peso, sin
  dimensiones, sin flags de apilable/frágil.** Categorías sin perfil logístico.

#### 1.5 Flota — `packages/logistics/fleet`

- `fleet_vehicles.capacity_kg` (int) y `capacity_m3` (decimal 10,3), `home_base_node_id`, `status`, perfil
  (marca/modelo/año/VIN/fotos). **No hay tipo de vehículo** (furgón, camión, moto, refrigerado) ni medidas
  internas de caja (largo/ancho/alto útil).
- Tripulaciones `fleet_crews` (driver, helpers, vehicle, `assembled_for`, `shift_starts_at/ends_at`).
- Reglas de turno `Domain/ValueObject/ShiftRules.php` (jornada máx., ventana de inicio/fin, descanso mínimo) +
  `Domain/Service/CrewShiftScheduler.php`, configurables en la sección **Crew shifts** de Configuración
  (`Presentation/Filament/Configuration/CrewShiftsSection.php`). **Se validan al ARMAR la tripulación, pero el
  planificador no las usa**: `FleetVehicleAvailabilityProvider` solo mira `hasCrew` (existe tripulación ese día).

#### 1.6 Planificación — `packages/logistics/planning` (la pieza más madura)

| Pieza | Estado | Archivo |
|---|---|---|
| Agregado `DeliveryPlan` (Draft → Proposed → Published), un plan por (vehículo, fecha), paradas con ETA y carril de carga (LIFO) | **Funciona** | `Domain/Model/DeliveryPlan.php`, `PlannedStop.php`, `ValueObject/LoadLane.php` |
| Auto-planner `GenerateDailyPlan(date, strategy)` asíncrono, idempotente por día+modo, modos `optimization`/`slots` | **Funciona** (ADR 012) | `Application/Service/DailyPlanRunner.php`, `Infrastructure/Queue/GenerateDailyPlanJob.php`, `Domain/Model/AutoPlanRun.php` |
| Empaque first-fit-decreasing por volumen, agrupado por zona, un camión = un departamento (+ una franja en modo slots) | **Funciona** | `Domain/Service/DailyPlanComposer.php`, `PackingBin.php` |
| VRP: Clarke-Wright + RouteSequencer (NN/EDD + 2-opt + Or-opt) con **ventanas blandas** (espera + penalización por retraso) sobre matriz OSRM `/table` cacheada en Valkey | **Funciona** | `Domain/Service/ClarkeWrightSolver.php`, `RouteSequencer.php`, `Infrastructure/Routing/BuiltinRoutingBackend.php`, `OsrmRoadMatrixProvider.php` |
| Backends enchufables `builtin` / `osrm_trip` / `vroom` (fallback a builtin) + comparación de backends y score de calidad | **Funciona** | `config/logistics-planning.php` (`routing.backend`), `VroomRoutingBackend.php`, `CompareRoutingBackends`, `RouteQualityScorer.php` |
| Geometría por calles persistida (`planning_plans.route_geometry`) | **Funciona** | `OsrmRouteGeometryProvider.php`, `PlanGeometryRefresher.php`, `BackfillPlanGeometry` |
| Restricciones duras camión↔envío (tipo de carga, zona permitida/prohibida, altura y peso por bulto) | **Motor listo, datos vacíos**: las tablas `planning_vehicle_restrictions` / `planning_shipment_requirements` **no tienen escritor** (solo tests). | `Domain/Service/VehicleCompatibility.php`, `ValueObject/ShipmentRouteRequirement.php`, `VehicleRouteProfile.php` |
| Asignación/reasignación/reorden/reoptimización manual | **Funciona** | `AssignShipmentToVehicle`, `ReassignShipmentToVehicle`, `ReorderStops`, `ReoptimizeRoute` |
| Feriados respetados en el pending | **Funciona** | `Infrastructure/Adapter/BlackoutAwarePendingDeliveryProvider.php` |
| Replanificación por incidente grave (backlog para el día siguiente) | **Parcial**: el reactor escribe `planning_shipment_replans`, pero **ningún lector lo consume** en el pending del auto-planner. | `Infrastructure/Outbox/VehicleIncidentReplanningDispatcher.php`, `ScheduleReplan`, `EloquentReplanBacklogRepository.php` |
| Settings tunables (hora de salida, holgura, peso/volumen por bulto) | **Parcial**: en `config/logistics-planning.php` (`auto_planner.*`), con provider `ConfiguredAutoPlanSettings`; **sin sección en Configuración**. La sección **Routing** (backend/OSRM/VROOM) sí existe: `settings/src/Presentation/Filament/Configuration/Integrations/RoutingSection.php`. | — |
| Admin: `PlanResource`, `PlannedStopResource`, acción "Auto-plan the day" | **Funciona** | `Presentation/Filament/PlanResource.php` |
| API `app`: `/plans`, `/plans/auto`, `/{id}/eta`, `/{id}/stops`, `/{id}/sequence`, `/{id}/publish` | **Funciona** | `routes/api/planning.php` |

**Brechas críticas detectadas en el código (no obvias):**

1. `EloquentPendingDeliveryProvider::pendingFor()` **ignora la fecha**: toma todos los envíos `RECEIVED` con
   coordenadas, aunque `shipments.delivery_date` ya existe (el comentario del adaptador lo reconoce). Un auto-plan
   del martes puede meter pedidos prometidos para el viernes.
2. Peso/volumen = `bultos × 10 kg / 0,05 m³` (defaults de config): la "asignación por medidas" hoy es ficticia.
3. Lee `planning_shipment_requirements` **completa** sin `whereIn` (no escala a millones).
4. Tiempo de servicio por parada (descarga, firma, foto) **no se modela**: las ETA son solo viaje.
5. El auto-planner deja planes **Proposed**; nada los publica ni hay scheduler nocturno
   (`routes/console.php` no agenda `GenerateDailyPlan`).
6. `planning.plan_published` **no tiene reactor** en `packages/logistics/outbox/config/logistics-outbox.php`
   (cae al `NullMessageDispatcher`): publicar no arma la ruta de ejecución ni la salida de fleetops.

#### 1.7 Ejecución, flota operativa y telemetría

- **execution**: `VehicleRoute` (`execution_driver_routes` + `execution_driver_route_stops` con `eta`), comando
  `AssembleDailyRoute` que, si no recibe paradas, las ensambla desde el plan **publicado** vía
  `Infrastructure/*/DbPlannedRouteStopsProvider.php`; `AttachShipmentToRoute`; endpoint
  `POST /vehicles/{vehicleId}/route`; `POST /app/driver/route/start` (`app/Http/Controllers/App/DriverRouteController.php`)
  marca `ShipmentDeparted` → tracking «en camino». Incidentes `ReportVehicleIncident` (fanout a flota/planning/
  notificaciones). **El ensamblado es a demanda (lo pide la app), no automático.**
- **fleetops**: `Departure` (`fleetops_departures`: chofer, ayudantes, `route_ref` string, `load_weight_kg`,
  odómetro). **Se registra manualmente**; no nace de la ruta.
- **telemetry**: `telemetry_trips.route_id` / `telemetry_pings.route_id` existen (nullable); `TripTracker` abre/
  cierra trips por hueco temporal de pings. **No hay vínculo automático trip ↔ ruta planificada**, ni desvío vs
  plan, ni ETA dinámica.

---

### 2. Módulo 1 — Programación automática de horas (slotting + ETA)

#### 2.1 Objetivo y reglas de negocio

Que el cliente elija **solo la fecha**; el sistema le asigna automáticamente una **ventana** (franja) y, más
tarde, una **ETA precisa** (±30 min), y se la comunica. Reglas:

- **R1.1** La fecha elegida es un compromiso: el slotting **no mueve el día**; si no hay ventana factible ese día,
  se escala a operaciones (cola "Unslotted") — nunca se cambia la fecha en silencio.
- **R1.2** Respeta cupos por municipio/franja (`capacity_buckets`, unidad `deliveries` y, si están definidas,
  `weight`/`volume`), feriados/bloqueos y horizonte activo.
- **R1.3** Respeta preferencia del cliente si existe (AM/PM, "después de 14:00", "no antes de"), como restricción
  blanda con peso alto.
- **R1.4** Respeta turnos de tripulación (`ShiftRules`): no se ofrece una ventana fuera de la jornada disponible
  de las tripulaciones del departamento.
- **R1.5** Respeta disponibilidad de flota del departamento (camiones `available` con base en ese departamento):
  la capacidad *real* del día es `min(cupo configurado, capacidad de flota × factor)`.
- **R1.6** Ventana comprometida ≠ ETA. La ventana (p. ej. 09:00-13:00) se fija al slotear; la ETA estrecha
  (p. ej. 10:40 ±30) se fija al **publicar el plan** y se refina en ejecución.
- **R1.7** Reprogramación por incidente: incidente leve → empujar ETA y re-notificar si sale de la ventana;
  incidente grave → reprogramar al siguiente día despachable (con cupo) y notificar «reprogramado».
- **R1.8** Todo pedido igual sin importar dueño; el stock no se consulta.

#### 2.2 Estado actual

Ver §1.1–1.3. **Funciona** la reserva con día+ventana explícitos; **falta** la asignación automática de ventana,
la ETA de cliente, el uso de turnos/flota en la oferta y la re-notificación por desvío.

#### 2.3 Brechas

| # | Brecha | Impacto |
|---|---|---|
| B1.1 | No hay comando "reservar solo fecha" ni algoritmo de elección de ventana. | El canal debe elegir la franja por el cliente. |
| B1.2 | Capacidad ofrecida no considera flota/tripulación real del día. | Se pueden vender cupos que la flota no cubre. |
| B1.3 | No hay preferencia horaria del cliente en el envío. | No se puede honrar "solo tarde". |
| B1.4 | ETA de cliente inexistente; no hay evento ni notificación de ETA. | El cliente solo ve la franja amplia. |
| B1.5 | Sin re-slot automático ante incidente leve/grave (solo backlog sin consumidor). | Reprogramaciones manuales. |
| B1.6 | Gotcha caché 60 s de slots tras bloqueo. | Oferta desactualizada breve (aceptable, documentar). |

#### 2.4 Diseño propuesto

**Dueño:** `promise` (decide y compromete la ventana) apoyado en `capacity` (cupo) — la ETA la **calcula**
`planning` y la **comunica** `promise`/`notifications`. No se crea un contexto nuevo.

**Dominio (`promise`)**

- VO `CustomerTimePreference` (`none | morning | afternoon | after(HH:MM) | before(HH:MM) | between(a,b)`),
  inmutable, `toArray()` primitivo.
- VO `WindowCandidate` (TimeSlot, disponible por unidad, carga estimada de la franja, score).
- Servicio de dominio puro `WindowSelector`:

```text
select(date, municipality, candidates[], preference, policy) -> WindowCandidate | null
  factibles = candidates where disponible.deliveries >= 1
                         and (sin unidad weight  o disponible.kg  >= peso_estimado)
                         and (sin unidad volume  o disponible.m3  >= volumen_estimado)
                         and dentroDeTurnos(slot, policy.crewWindow)
  si vacío -> null
  para cada c en factibles:
     c.score = w_pref   * matchPreferencia(c, preference)        // 0..1, peso alto
             + w_bal    * (disponible/total)                      // balanceo de carga entre franjas
             + w_early  * (1 - índiceFranja/nFranjas)             // preferir temprano (opcional)
             + w_geo    * afinidadZona(c, zona_del_pedido)        // franjas donde ya hay pedidos cercanos
  return argmax(score), desempate estable por TimeSlot
```

  `afinidadZona` agrupa pedidos de la misma zona en la misma franja: hace que el módulo 3 arme rutas más
  cortas (sinergia directa con el modo `slots` del auto-planner).

- Agregado existente `DeliveryPromise` gana método `autoSlot(window, reason)` → evento
  `promise.delivery_window_assigned` (payload: shipment/order ref, date, window, strategy, score).
- Nuevo evento `promise.delivery_eta_committed` (al publicar plan, ver abajo) y
  `promise.delivery_window_changed` (re-slot).

**Aplicación (CQRS)**

| Tipo | Nombre | Notas |
|---|---|---|
| Command | `ReserveDeliveryDate(shipmentId, date, preference?, municipalityId?/lat,lng?)` | Atómico como `ReserveMunicipalitySlot`: resuelve candidatos → `WindowSelector` → `ConsumeCapacity` → `SetDeliverySlot`. Idempotente por (shipment, date). |
| Command | `ReslotShipment(shipmentId, reason, sameDayOnly)` | Libera cupo viejo, elige nuevo; si `sameDayOnly` y no hay, cae a cola Unslotted. |
| Command | `CommitDeliveryEta(shipmentId, etaAt, toleranceMin, source)` | Lo dispara el reactor de `planning.plan_published` y el de ETA dinámica. Idempotente por (shipment, eta redondeada). |
| Query | `ListDeliveryDates(municipality|lat,lng, from, to)` | Días con al menos una ventana factible (el canal muestra solo fechas). |
| Query | `GetDeliveryCommitment(shipmentId|orderRef)` | Fecha + ventana + ETA vigente (read model `promise_delivery_commitments`). |
| Query | `ListUnslottedShipments(date, department)` | Cola operativa. |

**Puerto nuevo hacia flota** `CrewCapacityProvider::forDepartmentOn(date)` → `{vehiclesAvailable, crewWindows[],
kgTotal, m3Total}` (adaptador de Infrastructure que lee `fleet` por su Query pública, nunca su dominio). Con él
`EffectiveSlotCapacity = min(cupo, ceil(flota × entregas_por_camión_por_franja))`.

**Tablas / migraciones (aditivas, `no-destructive-db-ops`)**

- `shipments`: `+ time_preference jsonb null`, `+ slot_source varchar(16) null` (`customer|auto|ops`),
  `+ committed_eta timestamptz null`, `+ eta_tolerance_min smallint null`.
- `promise_delivery_commitments` (read model: `shipment_id` PK, `order_reference`, `date`, `window`, `eta`,
  `status`, `updated_at`; índice `(date, status)`).
- `promise_unslotted_queue` (`shipment_id`, `date`, `reason`, `created_at`).

**API (puerta `channels` / `integrations`, OpenAPI en el mismo cambio)**

- `GET  /api/v1/channels/promises/delivery-dates?lat=&lng=&from=&to=`
- `POST /api/v1/channels/shipments/date-reservation` `{shipment_id|order_ref, date, preference?}` → `{date, window, slot_source}`
- `GET  /api/v1/channels/shipments/{orderRef}/commitment`
- `POST /api/v1/app/shipments/{id}/reslot` (operaciones)

**Reactores del outbox**

| Evento | Reactor | Efecto |
|---|---|---|
| `orders.order_paid` (fanout existente) | `OrderPaidCupoConfirmDispatcher` (existente) + **`AutoSlotOnOrderPaid`** (nuevo) | Si el envío tiene fecha pero no ventana → `ReserveDeliveryDate`. |
| `shipments.shipment_received` | **`AutoSlotOnShipmentReceived`** | Pedidos de Magento/POS que llegan con fecha y sin ventana. |
| `planning.plan_published` | **`CommitEtaOnPlanPublished`** | Por cada parada → `CommitDeliveryEta`. |
| `execution.vehicle_incident_reported` (fanout existente) | **`ReslotOnMajorIncident`** | Grave → `ReslotShipment(nextDispatchableDay)`. |
| `promise.delivery_window_assigned`, `promise.delivery_eta_committed`, `promise.delivery_window_changed` | `DomainEventNotificationDispatcher` + **elegibles al notificador SAP** | Aviso al cliente (email/push/SMS según cascada) y fire-and-forget a SAP si está activado. |

**Automatización / scheduler**

- `logistics:promise:autoslot-sweep` cada 10 min: envíos con fecha y sin ventana (red de seguridad si un reactor
  falló) → `ReserveDeliveryDate`. Idempotente.
- `logistics:promise:unslotted-alert` diario 17:00 (hora La Paz, ADR 011): resumen al supervisor.

**Admin Filament (inglés + es.json, sin UUIDs, `Select::make(...)->native(false)` buscables)**

- Página **"Unslotted deliveries"** (cola con acciones *Auto-slot*, *Pick window*, *Move date*).
- En `ShipmentResource`/Hub de envío: bloque "Delivery commitment" (date, window, ETA, source).
- **Configuración** → nueva sección `Promise › Auto scheduling` (registrada en el provider de promise):
  `enabled`, pesos `w_pref/w_bal/w_early/w_geo`, `deliveries_per_truck_per_window`, `eta_tolerance_min`,
  `notify_on_eta_change_min` (umbral de re-aviso).
- `DispatchCalendar`: mostrar "effective capacity" (cupo vs flota) por día+ventana.

**App chofer:** sin impacto en Fase 0-2; en Fase 3 la app muestra la ventana comprometida por parada.

#### 2.5 Fases

| Fase | Entregables | Tareas | Criterios de aceptación | Pruebas | Esfuerzo | Depende de |
|---|---|---|---|---|---|---|
| **1.0** Reserva por fecha | `WindowSelector` (pref + balanceo), `ReserveDeliveryDate`, `ListDeliveryDates`, endpoint channels, `slot_source` | VO preferencia; servicio; handler atómico; migración; OpenAPI; es.json | Cliente manda solo fecha → envío queda con ventana; cupo descontado; repetir no consume doble; día bloqueado → 422 "day unavailable" | Unit del selector (determinismo, empates, pref); Feature concurrente (2 reservas, 1 cupo) | 4 d | — |
| **1.1** Reactores de auto-slot | `AutoSlotOnOrderPaid`, `AutoSlotOnShipmentReceived`, sweep, cola Unslotted + página admin | Registrar en `logistics-outbox.php` (fanout); comando de consola; página Filament | Orden pagada con fecha y sin ventana → slotada < 1 min; sin ventana factible → aparece en cola | Feature de reactores (reentrega at-least-once idempotente) | 3 d | 1.0 |
| **1.2** Capacidad efectiva por flota/turnos | `CrewCapacityProvider`, cap efectiva en oferta y en `DispatchCalendar` | Adaptador a fleet; `ShiftRules` → ventanas de tripulación | No se ofrece ventana fuera del turno; con 0 camiones disponibles el día no aparece | Unit + Feature con flota sembrada | 3 d | 1.0 |
| **1.3** ETA comprometida | `CommitDeliveryEta`, reactor de `plan_published`, read model commitments, notificación "your delivery ETA" | Ver Módulo 3 Fase 3.2 (publicación) | Al publicar plan, cada envío tiene `committed_eta`; cliente recibe aviso una sola vez | Feature end-to-end con plan publicado | 3 d | 3.2 |
| **1.4** Re-slot por incidente y ETA dinámica | `ReslotOnMajorIncident`, re-aviso por desvío > umbral (con Módulo 3 Fase 3.4) | Reactor; regla de umbral | Incidente grave → envíos con nueva fecha+ventana y tracking «reprogramada» | Feature de fanout de incidente | 3 d | 1.1, 3.4 |
| **1.5** Configuración | Sección `Promise › Auto scheduling` | `ConfigurationSection` + provider settings | Cambiar pesos en admin cambia la elección sin deploy | Feature de sección (state/save) | 1,5 d | 1.0 |

#### 2.6 Riesgos y decisiones abiertas

- **D1.1** ¿El canal (Magento/POS) deja de mostrar franjas y muestra solo fechas, o se mantienen ambos modos? (Propuesta: ambos; si viene ventana se respeta, si no se auto-asigna.)
- **D1.2** ¿Se comunica la ventana al pagar (amplia) y la ETA la víspera, o solo la ETA? (Propuesta: ambas.)
- **D1.3** Ancho de ventanas comerciales (4 h vs 2 h) por municipio.
- **R1.a** Balanceo vs agrupamiento geográfico pueden tirar en sentidos opuestos → pesos configurables y medición.
- **R1.b** La capacidad efectiva depende de que las tripulaciones se carguen con anticipación; si se arman el mismo día, la oferta de días futuros usa el promedio histórico (fallback configurable).

---

### 3. Módulo 2 — Asignación automática al camión por medidas de producto

#### 3.1 Objetivo y reglas de negocio

Asignar cada envío a un camión según **peso y volumen reales** (derivados de las medidas de los productos), con
restricciones físicas (altura/largo máximos, apilable, frágil, tipo de carga), respetando la regla de un
departamento, la tripulación disponible y el tipo de vehículo.

- **R2.1** Capacidad: `Σ peso ≤ capacity_kg × (1 − slack)` y `Σ volumen_efectivo ≤ capacity_m3 × (1 − slack)`.
- **R2.2** Volumen efectivo = volumen geométrico × **factor de estiba** (por categoría/flag: no apilable ⇒ factor
  > 1, p. ej. heladera 1,3; caja apilable 1,0).
- **R2.3** Restricciones duras por bulto: largo/alto máximo de la caja del vehículo, peso por bulto (dos personas
  ⇒ requiere ayudante), tipo de carga (frágil, línea blanca, voluminoso).
- **R2.4** Un camión = un departamento (heredado). Un camión sin tripulación completa no recibe carga.
- **R2.5** **Medidas faltantes nunca bloquean**: cascada `producto → categoría → default global`, y se marca el
  producto como "datos incompletos" para la cola de completado.
- **R2.6** Override manual en admin y app (con motivo), auditable; un override **no** se pisa en la siguiente
  re-optimización salvo que el operador lo libere ("pinned").
- **R2.7** Dueño indiferente (OWNED/FBD/DROPSHIP con entrega propia se empaquetan igual).

#### 3.2 Estado actual

- Motor de empaque y compatibilidad **listo**: `DailyPlanComposer` (FFD por volumen), `PackingBin` (kg/m³ +
  departamento + franja), `VehicleCompatibility` + `ShipmentRouteRequirement` (load_type, zona, altura, peso por
  bulto) + `VehicleRouteProfile` (allowed/forbidden, max altura/peso).
- Capacidad de vehículo: `fleet_vehicles.capacity_kg/capacity_m3` (`packages/logistics/fleet`).
- **Datos de entrada ficticios**: peso/volumen = bultos × default (`config/logistics-planning.php`
  `auto_planner.default_*`). Catálogo y líneas de envío **sin medidas**. Tablas de requisitos/restricciones **sin
  escritor**.
- Asignación manual existente: `AssignShipmentToVehicle` / `ReassignShipmentToVehicle` (planning) y
  `AssignOrderVehicleController` (execution).

#### 3.3 Brechas

| # | Brecha |
|---|---|
| B2.1 | Producto sin peso/dimensiones/apilable/frágil; categoría sin perfil logístico por defecto. |
| B2.2 | `shipment_lines` no guarda la **foto** de medidas al momento del pedido (si el catálogo cambia, el plan cambia). |
| B2.3 | Vehículo sin tipo ni medidas de caja; restricciones sin UI ni comando. |
| B2.4 | El pending lee requisitos completos sin filtro y sin fecha (ver §1.6). |
| B2.5 | No hay cola de "datos logísticos incompletos". |
| B2.6 | Overrides manuales no se "fijan": una re-optimización puede deshacerlos. |
| B2.7 | Peso por bulto que requiere 2 personas no se cruza con el tamaño de la tripulación. |

#### 3.4 Diseño propuesto

**Propiedad de los datos (cada contexto dueño de lo suyo):**

| Dato | Dueño | Por qué |
|---|---|---|
| Medidas del producto (peso, L×A×H, apilable, frágil, `handling_class`) | `commerce/catalog` | Es un atributo del producto; Magento/POS pueden mandarlo. |
| Perfil logístico por defecto de categoría | `commerce/catalog` | Fallback de R2.5. |
| **Foto** de medidas por línea de envío | `shipments` | Inmutable desde que se recibe el envío (`ShipmentLine` lleva `unit_weight_kg`, `unit_dims_cm`, `stackable`, `fragile`, `measure_source`). |
| Carga agregada del envío (kg, m³ efectivos, bulto más largo/alto/pesado) | `planning` (read model `planning_shipment_loads`) | Es lo que el VRP necesita; se proyecta por evento. |
| Tipo y caja del vehículo, restricciones | `fleet` (tipo/caja) + `planning` (restricciones de ruteo, ya existe la tabla) | Fleet es maestro; planning traduce. |

**Dominio**

- `catalog`: VO `PhysicalSpec(weightKg, lengthCm, widthCm, heightCm, stackable, fragile, handlingClass)` en el
  agregado `Product` (y opcional por variante); VO `CategoryLogisticsDefaults`. Evento
  `catalog.product_physical_spec_changed`.
- `shipments`: VO `LineMeasure(unitWeightKg, dimsCm, stackable, fragile, source: product|category|default)`.
  El adaptador que crea el envío (`OrderPaidShipmentDispatcher` / pull Magento) resuelve la cascada **en
  Infrastructure** leyendo la Query pública de catalog. Evento existente `shipments.shipment_received` lleva ya la
  carga agregada.
- `planning`: VO `ShipmentLoad(weightKg, volumeM3, effectiveVolumeM3, maxLengthM, maxHeightM, heaviestPieceKg,
  needsTwoPeople, handlingClasses[])`. `DeliveryCandidate` pasa a construirse desde `ShipmentLoad` (sin cambiar el
  motor). `VehicleRouteProfile` + `crewSize`, `cargoBox(L,A,H)`, `vehicleType`.
- Política pura `LoadFeasibility::check(candidate, vehicle)` que extiende `VehicleCompatibility` con: largo del
  bulto ≤ largo de caja; `needsTwoPeople ⇒ crewSize ≥ 2`; `fragile` no admitido en `vehicleType=open_truck`.

**Algoritmo** (se mantiene la arquitectura actual; se mejora la heurística por fases):

```text
Fase 2.1 — FFD multi-dimensional (2D: kg y m³ efectivos), sobre el empaque actual:
  orden de candidatos = por zona, luego por max(kg/capKg_ref, m3/capM3_ref) desc   // "dominant resource"
  para cada candidato: primer bin compatible (depto, franja, LoadFeasibility) con holgura en kg y m³
  no asignados -> lista "Unassigned" con motivo (capacity | incompatible | no_crew)

Fase 2.3 — Mejora local post-ruteo (ya hay Clarke-Wright que mezcla capacidad + distancia):
  repetir hasta no mejorar o tope:
    mover/intercambiar un envío entre dos camiones del mismo depto si
      factibles ambos y (Δdistancia + λ·Δdesbalance_de_carga) < 0

Bin-packing 3D real (orientaciones, apilado): NO en el plan diario. Solo como verificación opcional
"¿cabe físicamente?" para camiones al >90 % de m³, con heurística extreme-point, en job asíncrono.
```

  Justificación: en reparto de última milla con ~300 entregas/día el cuello es volumen con factor de estiba, no la
  geometría exacta; el 3D cuesta caro y depende de medidas confiables que hoy no existen.

**CQRS / API**

| Tipo | Nombre | Puerta |
|---|---|---|
| Command | `DefineProductPhysicalSpec(productId, spec)` | `app` (admin) + `integrations` (Magento/POS envía medidas) |
| Command | `DefineCategoryLogisticsDefaults(categoryId, defaults)` | `app` |
| Command | `DefineVehicleCargoProfile(vehicleId, type, boxCm, crewSize?)` (fleet) | `app` |
| Command | `SetVehicleRouteRestrictions(vehicleId, …)` (planning; primer escritor de `planning_vehicle_restrictions`) | `app` |
| Command | `PinShipmentToVehicle(planDate, shipmentId, vehicleId, reason)` / `UnpinShipment` | `app` (admin y app supervisor) |
| Query | `ListIncompleteProductMeasures(category?, page)` | `app` |
| Query | `GetVehicleLoadSummary(planId)` → kg/m³ usados, % ocupación, bultos especiales | `app` |

Endpoints: `PUT /api/v1/app/catalog/products/{id}/physical-spec`,
`PUT /api/v1/integrations/catalog/products/{sku}/physical-spec` (por SKU, no UUID),
`PUT /api/v1/app/fleet/vehicles/{id}/cargo-profile`, `PUT /api/v1/app/planning/vehicles/{id}/restrictions`,
`POST /api/v1/app/plans/{planId}/pins`, `GET /api/v1/app/plans/{planId}/load`.

**Tablas / migraciones (aditivas)**

- `commerce_products`: `+ weight_kg numeric(10,3) null`, `+ length_cm/width_cm/height_cm numeric(8,1) null`,
  `+ stackable bool null`, `+ fragile bool null`, `+ handling_class varchar(32) null`.
- `commerce_categories`: `+ logistics_defaults jsonb null`.
- `shipment_lines`: `+ unit_weight_kg`, `+ unit_length_cm/width_cm/height_cm`, `+ stackable`, `+ fragile`,
  `+ measure_source varchar(16)`.
- `planning_shipment_loads` (PK `shipment_id`, kg, m³, m³ efectivo, max dims, flags; índice por `plan_date`),
  **reemplaza** la lectura completa de `planning_shipment_requirements` (se mantiene por compatibilidad).
- `fleet_vehicles`: `+ vehicle_type varchar(24) null`, `+ box_length_cm/box_width_cm/box_height_cm null`.
- `planning_plan_pins` (`plan_date`, `shipment_id` unique por fecha, `vehicle_id`, `reason`, `actor varchar`).

**Reactores**

| Evento | Reactor | Efecto |
|---|---|---|
| `shipments.shipment_received` | `ProjectShipmentLoad` (planning) | Upsert `planning_shipment_loads` (sin I/O externo). |
| `catalog.product_physical_spec_changed` | `FlagRecomputeLoads` (planning) | Recalcula cargas de envíos **aún no planificados** (los publicados quedan con su foto). |
| `planning.shipment_pinned` / `planning.vehicle_load_exceeded` | Notificaciones supervisor + **elegibles a SAP** | Aviso operativo. |

**Admin Filament**

- Catálogo: pestaña **"Shipping dimensions"** en el producto (weight, L/W/H en cm, Stackable, Fragile, Handling
  class — `Select::make('handling_class')->native(false)`), y en categoría "Logistics defaults".
- Página **"Incomplete product data"** (cola, edición inline, export) — siempre nombre + SKU del producto, nunca
  "bulto".
- Vehículo (Hub): sección "Cargo profile" + "Route restrictions" (selects buscables de zonas reales).
- Plan: barra de ocupación kg/m³ por camión, badges (fragile, 2-person), acción **"Move to truck"** (select
  buscable por placa) que crea un pin.
- **Configuración** → sección `Planning › Load rules`: `capacity_slack`, defaults globales de peso/volumen,
  factor de estiba por `handling_class`, umbral "two-person piece kg", habilitar verificación 3D.

**App chofer/supervisor:** el supervisor (rol con permiso) puede mover un envío a otro camión antes de la carga
(`POST /plans/{id}/pins`); el chofer ve en la parada los badges *Fragile* / *2 people* y el carril de carga.

#### 3.5 Fases

| Fase | Entregables | Tareas | Criterios de aceptación | Pruebas | Esfuerzo | Depende de |
|---|---|---|---|---|---|---|
| **2.0** Medidas en catálogo | Columnas + `PhysicalSpec` + comando + pestaña admin + endpoint integrations por SKU | Migración aditiva; VO; handler; Filament; OpenAPI | Producto guarda medidas; Magento puede enviarlas por SKU | Unit VO (rangos); Feature endpoint | 3 d | — |
| **2.1** Foto en línea + carga del envío | Cascada producto→categoría→default; `planning_shipment_loads`; pending usa carga real; **pending filtra por `delivery_date`** y lee cargas con `whereIn` | Adaptador en creación del envío; reactor de proyección; ajustar `EloquentPendingDeliveryProvider` | Auto-plan del día X solo toma envíos del día X; kg/m³ reales en el plan; sin medidas → default + flag | Feature: envío sin medidas planifica igual; plan de otro día no lo toma | 4 d | 2.0 |
| **2.2** Perfil de carga del vehículo | Tipo, caja, crewSize; `SetVehicleRouteRestrictions`; `LoadFeasibility` | Migración fleet; comandos; UI | Bulto de 2,4 m no va a furgón de 2,0 m; pieza > umbral exige crew ≥ 2 | Unit de la política; Feature de compose | 3 d | 2.1 |
| **2.3** Heurística mejorada | FFD por recurso dominante + mejora local inter-camión | Domain service puro | Ocupación media ≥ actual en dataset de prueba; determinista | Benchmark reproducible (seed) + Unit | 3 d | 2.1 |
| **2.4** Overrides fijados | Pins + UI "Move to truck" + endpoint app | Agregado/tabla pins; solver respeta pins | Re-optimizar no mueve un envío fijado; auditoría con actor | Feature | 2,5 d | 2.1 |
| **2.5** Cola de datos incompletos | Página + query + alerta semanal | Filament; scheduler | Lista ordenada por frecuencia de venta | Feature | 1,5 d | 2.1 |
| **2.6** (opcional) Verificación 3D | Job asíncrono extreme-point para camiones > 90 % m³ | Servicio puro; reporte | Marca "may not fit" sin bloquear | Unit con casos conocidos | 4 d | 2.2 |

#### 3.6 Riesgos y decisiones abiertas

- **D2.1** ¿Fuente maestra de medidas: Magento, SAP o el admin? (Propuesta: integrations por SKU desde Magento; el admin corrige; SAP se notifica fire-and-forget.)
- **D2.2** Factores de estiba por clase: requieren una semana de medición en CD.
- **D2.3** ¿Peso por bulto que exige 2 personas (p. ej. > 40 kg)?
- **R2.a** Datos de medidas pobres al inicio → la asignación seguirá dependiendo de defaults; mitigación: métrica "% de envíos con medida real" en el cockpit.
- **R2.b** Cambiar a carga real puede **bajar** la cantidad de pedidos por camión vs hoy (los defaults subestiman línea blanca) → comunicar a operaciones.

---

### 4. Módulo 3 — Armado automático de la ruta y del viaje

#### 4.1 Objetivo y reglas de negocio

Que, sin intervención, cada noche (y ante cambios) se construya por camión la **ruta ordenada** (VRPTW), el
**viaje** (hora de salida, ETA por parada, geometría), se **publique**, se **ensamble** la ruta de ejecución del
chofer, se cree la **salida** en fleetops y se vincule el **trip** de telemetría.

- **R3.1** Ruta dentro de un departamento; orden por cercanía con ventanas (heredado).
- **R3.2** Hora de salida = `max(inicio de turno de la tripulación, fin de carga estimado)`; fin de ruta ≤ fin de
  turno (`ShiftRules.maxDailyMinutes`).
- **R3.3** ETA = viaje (matriz OSRM) + **tiempo de servicio** por parada (base + por bulto + por `handling_class`).
- **R3.4** Publicación: automática si el plan cumple umbrales de calidad (ver `RouteQualityScorer`) y no hay
  envíos sin asignar críticos; si no, queda Proposed y se alerta al planificador (el humano decide — ADR 012).
- **R3.5** Plan publicado es inmutable salvo **re-optimización controlada** (`ReoptimizeRoute`, ya existe) que
  re-emite ETA y avisa solo si cambia > umbral.
- **R3.6** Recompute ante: envío nuevo para el día (antes del corte), cancelación, incidente, camión fuera de
  servicio, override.
- **R3.7** I/O a OSRM/VROOM solo en jobs; si el ruteador cae → fallback haversine + nearest-neighbor (ya existe).

#### 4.2 Estado actual

Ver §1.6–1.7: VRP, geometría, ETA de viaje, publicación manual y ensamblado de ruta a demanda **funcionan**.
Falta la **cadena automática** (scheduler → publicación → ensamblado → salida → trip), tiempo de servicio,
consumo del backlog de replanificación, ventanas como restricción dura y la vista del viaje completa.

#### 4.3 Brechas

| # | Brecha |
|---|---|
| B3.1 | Sin scheduler de auto-plan nocturno por departamento (`routes/console.php`). |
| B3.2 | Pending ignora fecha y backlog de replanificación (`planning_shipment_replans` no se consume). |
| B3.3 | Sin tiempo de servicio; ETA optimista. |
| B3.4 | Hora de salida global (`PLANNING_DEPART_TIME=07:30`), no por tripulación/turno. |
| B3.5 | `planning.plan_published` sin reactor → la ruta de ejecución no se arma sola; fleetops `Departure` manual; trip de telemetría sin `route_id`. |
| B3.6 | No hay auto-publicación con gate de calidad. |
| B3.7 | Re-plan incremental (insertar un envío tardío en un plan Proposed) no existe; hoy se regenera el día. |
| B3.8 | ETA dinámica en ruta (posición real vs plan) inexistente. |
| B3.9 | Ventanas solo blandas en `builtin`; con VROOM serían duras (ADR 009) pero requiere OSRM self-host Bolivia. |

#### 4.4 Diseño propuesto

**Dueños:** `planning` (plan, ruta, viaje planificado, publicación), `execution` (ruta del chofer), `fleetops`
(salida), `telemetry` (trip real). Se conectan **solo por eventos del outbox**.

**Dominio (`planning`)**

- VO `ServiceTimePolicy(baseSeconds, perPieceSeconds, byHandlingClass[])` → `serviceSecondsFor(load)`.
- VO `TripPlan(departAt, returnAt?, totalDistanceM, totalDurationS, stops[{seq, etaAt, etdAt, windowOk}])` en el
  agregado `DeliveryPlan` (la geometría ya está).
- `RouteSequencer` y `BuiltinRoutingBackend` reciben la matriz **+ vector de servicio**; costo = viaje + servicio +
  espera + penalización de retraso (cambio local, sin romper la interfaz `RoutingBackend`).
- Servicio puro `PublishGate::evaluate(plan, qualityScore, thresholds)` → `publish | hold(reasons[])`.
- Eventos nuevos: `planning.trip_planned` (salida, ETA, distancia), `planning.plan_auto_published`,
  `planning.plan_held_for_review`, `planning.route_eta_shifted` (re-optimización con desvío).

**Orquestación (Application)**

| Tipo | Nombre | Notas |
|---|---|---|
| Command | `RunNightlyPlanning(date, departmentId?)` | Por departamento: dispara `GenerateDailyPlan(date, strategy=slots, department)`; idempotente por (date, dept, mode). |
| Command | `AutoPublishPlans(runId)` | Evalúa `PublishGate` por plan; publica o retiene. |
| Command | `InsertLateShipment(date, shipmentId)` | Inserción de menor costo en un plan Proposed del depto; si no cabe, a "Unassigned". |
| Command | `RecomputeDay(date, departmentId, reason)` | Re-plan acotado (solo planes no publicados). |
| Query | `GetTripPlan(planId)` / `ListTripsByDate(date, dept)` | Read model con placa, chofer, salida, ETA, ocupación (sin UUIDs en admin). |

**Execution / fleetops / telemetry (reactores nuevos, todos idempotentes)**

| Evento | Reactor (contexto) | Efecto |
|---|---|---|
| `planning.plan_published` | `AssembleRouteOnPlanPublished` (execution) | `AssembleDailyRoute(vehicleId, date)` sin paradas → las toma de `DbPlannedRouteStopsProvider` (ya existe). Si ya existe ruta → `AttachShipmentToRoute` de las nuevas. |
| `planning.plan_published` | `CommitEtaOnPlanPublished` (promise, Módulo 1) | ETA al cliente. |
| `execution.driver_route_assembled` | `PrepareDepartureOnRouteAssembled` (fleetops) | Crea `Departure` en estado **planned** (nuevo estado) con chofer/ayudantes de la tripulación, `route_ref` = ruta, carga kg del plan. |
| `execution.shipment_departed` (primer envío de la ruta, vía `POST /app/driver/route/start`) | `OpenDepartureOnRouteStart` (fleetops) + `BindTripToRoute` (telemetry) | Departure → `out` con odómetro; el trip activo del vehículo recibe `route_id`; los pings posteriores se etiquetan. |
| `telemetry.trip_completed` / última parada cerrada | `CloseDepartureOnRouteCompleted` (fleetops) | Departure → `returned`, km de telemetría. |
| `telemetry.position` (batch, no evento por ping) | `DynamicEtaProjector` (planning, job cada 2-5 min) | Recalcula ETA restante; si desvío > umbral → `planning.route_eta_shifted` → aviso al cliente + promise `CommitDeliveryEta`. |
| `execution.vehicle_incident_reported` (fanout existente) | `VehicleIncidentReplanningDispatcher` (existe) + **consumir backlog** | El pending del día siguiente **une** `planning_shipment_replans`. Leve → `ReoptimizeRoute` con retraso o `ReassignShipmentToVehicle`. |
| Todos los eventos nuevos | `DomainEventNotificationDispatcher` / notificador SAP | Elegibles para SAP (fire-and-forget). |

**Scheduler (zona horaria de la app, ADR 011)**

```text
21:00  logistics:planning:nightly --date=tomorrow     // por cada departamento con envíos -> RunNightlyPlanning
       (job en cola 'planning', ShouldBeUnique por date+dept)
21:45  logistics:planning:auto-publish --date=tomorrow // PublishGate; retenidos -> alerta supervisor
05:30  logistics:planning:refresh --date=today         // re-plan de no publicados + inserción de tardíos + geometría faltante
*/3min logistics:planning:dynamic-eta                  // solo rutas en curso
```

Horarios configurables en Configuración (no hardcode); cada comando es idempotente y re-ejecutable.

**Pseudo-código del job nocturno por departamento**

```text
RunNightlyPlanning(date, dept):
  pending  = shipments where delivery_date = date and status RECEIVED and dept(shipment) = dept
           ∪ replan_backlog(date, dept)
           − blackout(municipio, date)
  loads    = planning_shipment_loads[pending]            // Módulo 2
  fleet    = vehicles available in dept with crew(date); departAt_v = max(shiftStart_v, loadReady)
  pins     = planning_plan_pins(date)
  result   = RoutingBackend.route(pending+loads, fleet, windows, serviceTimes, pins)
  for route in result.routes: upsert DeliveryPlan(vehicle, date) -> proposeRoute + geometry + TripPlan
  unassigned -> planning.daily_plan_generated (con motivos) -> cockpit
```

**Tablas / migraciones (aditivas)**

- `planning_plans`: `+ depart_at timestamptz`, `+ planned_end_at timestamptz`, `+ service_seconds_total int`,
  `+ publish_mode varchar(8)` (`manual|auto`), `+ held_reasons jsonb null`.
- `planning_plan_stops`: `+ etd timestamptz null`, `+ service_seconds int null`, `+ window_ok bool null`.
- `planning_shipment_replans`: `+ consumed_at timestamptz null` (consumo idempotente).
- `fleetops_departures`: `+ route_id uuid null` (índice), estado `planned`.
- `telemetry_trips.route_id` ya existe (se empieza a llenar).

**API (OpenAPI en el mismo cambio)**

- `POST /api/v1/app/plans/nightly` `{date, department_id?}` (disparo manual del mismo job).
- `POST /api/v1/app/plans/{planId}/hold` / `.../release`.
- `GET  /api/v1/app/plans/trips?date=&department=` (vista viaje).
- `GET  /api/v1/app/driver/route` existente: + `depart_at`, `etd`, `window`, `committed_eta` por parada.
- `POST /api/v1/app/plans/late-shipments` `{date, shipment_id}`.

**Admin Filament**

- **"Route planning board"** (página por fecha+departamento): mapa con geometrías por camión (Leaflet local, CERO
  CDN), tabla de camiones (placa, chofer, salida, fin estimado vs fin de turno, ocupación kg/m³, paradas, % en
  ventana, score de calidad), lista "Unassigned" con motivo, acciones *Run planning*, *Publish*, *Hold*,
  *Move to truck*. Sin UUIDs (helpers `VehiclePlates`/`UserNames`/`NodeNames`).
- `PlanResource` → vista "Trip" (timeline de paradas con ETA/ETD y ventana).
- **Configuración** → sección `Planning › Automation`: horarios nocturno/publicación/refresh, estrategia por
  defecto, `PublishGate` (score mínimo, % máx. fuera de ventana, máx. unassigned), tiempos de servicio, umbral de
  re-aviso de ETA, habilitar ETA dinámica. (La sección `Integrations › Routing` existente sigue siendo la del
  backend/OSRM/VROOM.)

**App chofer (Flutter `logistics-app`)**

- La ruta del día aparece sola tras la publicación (push FCM `driver_route_ready` vía outbox).
- Hora de salida sugerida, ETA/ventana por parada, badges de manejo, carril de carga.
- Botón "Start route" (existe en web; exponer en API app) → dispara salida fleetops + vínculo de trip.
- Recepción de re-optimización ("route updated") con diff de orden.

#### 4.5 Fases

| Fase | Entregables | Tareas | Criterios de aceptación | Pruebas | Esfuerzo | Depende de |
|---|---|---|---|---|---|---|
| **3.0** Correcciones de base | Pending filtra por `delivery_date` y departamento; consume backlog de replan; `whereIn` de requisitos | Ajustar `EloquentPendingDeliveryProvider` / `BlackoutAwarePendingDeliveryProvider`; `consumed_at` | Plan del día solo con envíos del día + reprogramados; consumo idempotente | Feature con 3 fechas mezcladas | 2 d | — (comparte tarea con 2.1) |
| **3.1** Tiempo de servicio + salida por turno | `ServiceTimePolicy`, `depart_at` por tripulación, fin ≤ turno | Cambios en sequencer/backend (puros); provider de turnos | ETA incluye servicio; ninguna ruta excede fin de turno (o queda retenida con motivo) | Unit sequencer con servicio; Feature runner | 3 d | 3.0 |
| **3.2** Nocturno + auto-publicación | Comandos `nightly`/`auto-publish`, `PublishGate`, scheduler, hold/release | Console + scheduler + eventos | A las 21:45 los planes que pasan el gate quedan Published; retenidos alertan | Feature con `Carbon::setTestNow`; Unit gate | 3 d | 3.1 |
| **3.3** Cadena publicación → ruta → salida → trip | Reactores `AssembleRouteOnPlanPublished`, `PrepareDepartureOnRouteAssembled`, `OpenDepartureOnRouteStart`, `BindTripToRoute`, `CloseDepartureOnRouteCompleted` | Registro en `logistics-outbox.php`; estado `planned` en fleetops; endpoint start en API app | Publicar → ruta del chofer visible sin acción; iniciar → departure `out` y pings con `route_id`; cerrar → `returned` con km | Integración end-to-end (patrón `TrackingLifecycleTest`), reentrega at-least-once | 4 d | 3.2 |
| **3.4** Recompute e inserción tardía | `InsertLateShipment`, `RecomputeDay`, refresh 05:30, re-opt por incidente leve | Inserción de menor costo; reactores | Envío tardío entra al plan correcto sin regenerar el día; incidente leve empuja ETA | Unit inserción; Feature | 3 d | 3.2 |
| **3.5** ETA dinámica | `DynamicEtaProjector`, `route_eta_shifted`, aviso con umbral | Job; lectura de posición viva (`telemetry_vehicle_positions`) | Desvío > umbral → 1 aviso por parada (no spam) | Feature con pings simulados | 3 d | 3.3, 1.3 |
| **3.6** Board de planificación + app | Página "Route planning board", vista Trip, contrato app actualizado | Filament + OpenAPI + es.json | Planificador opera el día completo desde una pantalla, sin UUIDs | Smoke Filament + verificación manual en containers | 4 d | 3.2 |
| **3.7** (opcional) VRPTW duro | OSRM self-host Bolivia + VROOM con ventanas/skills/servicio | Infra (ADR 013) + mapping | Comparación `CompareRoutingBackends` muestra VROOM ≥ builtin | Benchmark | 4 d | 3.1 |

#### 4.6 Riesgos y decisiones abiertas

- **D3.1** ¿Auto-publicar o siempre revisión humana? ADR 012 dice "el auto-planner asiste". Propuesta: auto-publicar **solo** con gate verde y flag en Configuración (off por defecto el primer mes).
- **D3.2** Hora de corte de pedidos para el día siguiente (antes del nocturno 21:00).
- **D3.3** ¿Ruta abierta (termina en la última entrega, como hoy) o cerrada (vuelve al CD)? Afecta fin de turno.
- **R3.a** OSRM público como dependencia (rate limit) → caché de matriz ya existe; OSRM propio recomendado antes de 3.5.
- **R3.b** Reactores encadenados: un fallo en fleetops no debe frenar execution → cada reactor independiente en el fanout, idempotente, con dead-letter visible en el cockpit.

---

### 5. Secuencia end-to-end: pedido recibido → slot → asignación → ruta → viaje → app chofer

```text
Cliente (Magento/POS)        commerce/shipments         promise/capacity          planning               execution / fleetops / telemetry      notifications / SAP
       |  checkout: elige FECHA      |                          |                      |                           |                                   |
       |---------------------------->| orders.order_paid ──────>| AutoSlotOnOrderPaid  |                           |                                   |
       |                             | ReceiveShipment          | ReserveDeliveryDate  |                           |                                   |
       |                             |  (foto de medidas, M2)   |  WindowSelector      |                           |                                   |
       |                             |                          |  ConsumeCapacity     |                           |                                   |
       |                             |<── SetDeliverySlot ──────|                      |                           |                                   |
       |                             | shipment_received ───────────────────────────-->| ProjectShipmentLoad (M2)  |                                   |
       |                             |                          | promise.delivery_window_assigned ──────────────────────────────────────────────> aviso "tu ventana 09-13" + SAP (f&f)
       |                             |                          |                      |                           |                                   |
  21:00 (scheduler)                  |                          |                      | RunNightlyPlanning(dept)  |                                   |
       |                             |                          |                      |  pending(fecha)+backlog   |                                   |
       |                             |                          |                      |  cargas reales + pins     |                                   |
       |                             |                          |                      |  VRPTW + servicio + turno |                                   |
       |                             |                          |                      |  geometría OSRM           |                                   |
  21:45                              |                          |                      | PublishGate → plan_published ──> AssembleRouteOnPlanPublished       |
       |                             |                          |<─ CommitEtaOnPlanPublished                       |   driver_route_assembled ──> fleetops Departure(planned)
       |                             |                          | delivery_eta_committed ────────────────────────────────────────────────────────> aviso "llega ~10:40" + push chofer "ruta lista"
  07:30 chofer abre la app           |                          |                      |                           | GET /app/driver/route (ETA/ventana/carril)
       |                             |                          |                      |                           | carga (scans) → shipment_loaded → tracking «cargado»
       |                             |                          |                      |                           | Start route → shipment_departed → Departure(out) + Trip.route_id
       |                             |                          |                      | DynamicEtaProjector <── posición viva                         |
       |                             |                          |<── route_eta_shifted (si desvío > umbral) ────────────────────────────────────> re-aviso al cliente
       |                             |                          |                      |                           | POD → shipment_delivered → tracking «entregado»
       |                             |                          |                      |                           | última parada → Departure(returned, km telemetría)
  Incidente grave                    |                          | ReslotOnMajorIncident (día siguiente) <── vehicle_incident_reported (fanout) ──> «reprogramado»
```

Tolerancia a fallos en cada salto: todos los saltos entre contextos son reactores del outbox (at-least-once,
idempotentes por `event_id`, `SKIP LOCKED` + lease). Si SAP/FCM/SMS fallan, solo se reintenta ese destino; el plan,
la ruta y la entrega siguen.

---

### 6. Plan consolidado y orden recomendado

| Ola | Contenido | Esfuerzo | Valor |
|---|---|---|---|
| **Ola A — base correcta** | 3.0 (pending por fecha + backlog), 2.0, 2.1 | ~9 d | El auto-plan deja de mezclar días y usa medidas reales/estimadas trazables. |
| **Ola B — slotting automático** | 1.0, 1.1, 1.5, 1.2 | ~11,5 d | El cliente elige fecha; la ventana se asigna sola respetando cupos, flota y turnos. |
| **Ola C — cadena automática** | 3.1, 3.2, 3.3, 1.3 | ~13 d | Nocturno → publicación → ruta del chofer → salida → trip, con ETA al cliente. |
| **Ola D — robustez y control** | 2.2, 2.4, 2.5, 3.4, 1.4, 3.6 | ~17,5 d | Restricciones físicas, overrides, recompute, board de planificación. |
| **Ola E — optimización** | 2.3, 3.5, 2.6, 3.7 | ~14 d | Mejor ocupación, ETA dinámica, 3D y VRPTW duro con VROOM. |

**Total estimado:** ~65 días-persona (paralelizable por territorios disjuntos: `promise` / `catalog+shipments` /
`planning` / `execution+fleetops+telemetry`, con Pest serializado por el coordinador — ver memoria de agentes en
paralelo). El dueño único de `openapi/v1.yaml` y `lang/es.json` debe fijarse por ola.

#### Checklist transversal por fase (DoD)

- [ ] Migraciones solo aditivas, idempotentes; nada de `migrate:fresh`.
- [ ] Domain PHP puro; adaptadores cross-context en Infrastructure leyendo Queries públicas o tablas en solo lectura.
- [ ] Eventos nuevos con nombre `<contexto>.<hecho>` registrados en `logistics-outbox.php` y **elegibles en el notificador SAP** de Configuración.
- [ ] OpenAPI actualizado en el mismo cambio; contrato de la app Flutter revisado.
- [ ] Admin: textos en inglés + `es.json`, `Select::make(...)->native(false)` buscables, sin UUIDs, producto siempre "nombre + SKU".
- [ ] Tunables en una sección de `/admin/configuration` (registro por contexto), defaults funcionales en docker nuevo.
- [ ] Pest + PHPStan 8 + Pint verdes, verificación real en containers, números de tests/aserciones reportados.

---

### 7. Decisiones que necesita el dueño (resumen)

1. **D1.1/D1.2** — ¿El canal muestra solo fechas? ¿Se comunica ventana al pagar y ETA la víspera?
2. **D1.3** — Ancho estándar de ventana por municipio (2 h / 4 h).
3. **D2.1** — Fuente maestra de medidas de producto (Magento vs admin vs SAP).
4. **D2.3** — Umbral de peso por pieza que exige dos personas.
5. **D3.1** — Auto-publicación con gate de calidad (on/off, umbrales).
6. **D3.2** — Hora de corte de pedidos para el día siguiente y hora del nocturno.
7. **D3.3** — Ruta abierta vs cerrada (regreso al CD) para el cálculo de turno.
8. ¿Se invierte en OSRM self-host Bolivia (+VROOM) antes de la ETA dinámica (Ola E) o en la Ola C?

---

## 03 — Etiquetas Lite / chicas para productos pequeños

> Sección de roadmap. Estado: **propuesta** (nada implementado). Fecha: 2026-11-12.
> Contexto relacionado: `docs/adr/004-etiquetas-qr-firmadas-ed25519.md`, sección «Etiquetas QR» de `CLAUDE.md`,
> `packages/logistics/labels/README.md`.

### 1. Objetivo y reglas

**Problema.** Hoy toda etiqueta de producto sale en un único formato de ~100 × 76 mm pensado para la Zebra
ZQ630 Plus de 4". En un producto chico (la caja de un celular, unos audífonos, un cargador) la etiqueta
tapa media caja, se ve fea y el cliente quiere despegarla (o revender el equipo con su caja intacta).

**Objetivo.** Un formato de etiqueta **más chico** que:

1. siga siendo escaneable de forma confiable con los celulares de gama baja que usa la operación y con los
   lectores de almacén;
2. lleve **la misma identidad** (`label_id`, ULID de 26 caracteres), para que todo el flujo —preparación,
   carga/descarga, POD, tracking, bitácora— funcione **sin cambios de proceso**;
3. sea solo una **forma de imprimir** la misma etiqueta, no una etiqueta nueva.

**Reglas que esta sección respeta (decisiones ya tomadas por el dueño):**

- Las etiquetas son **GLOBALES y PERMANENTES**: nunca se revocan ni se re-emiten con id nuevo. Reimprimir la
  misma etiqueta (mismo id / mismo QR) está permitido. ⇒ El tamaño es un **atributo de presentación**: se
  puede reimprimir la misma etiqueta en Estándar o en Lite cuantas veces haga falta.
- Las etiquetas son **ABIERTAS por defecto** (`k:0`, sin clave; `config/logistics-labels.php` → `open`). La
  validez la da el **registro**: el `label_id` existe en `labels_emitted` y pertenece al pedido.
- El JSON del QR y `LabelPayload::canonical()` son **contrato con la app Flutter**. Si cambian: subir `v`,
  regenerar el vector (`packages/logistics/labels/tests/Support/generate-test-vector.php`) y copiarlo a
  `logistics-app/test/fixtures/label_test_vector.json`. **Objetivo de diseño: Lite NO toca ese contrato.**
- Todo pedido se trata igual sin importar la propiedad (OWNED/FBD/DROPSHIP): la regla de tamaño depende
  del **producto**, nunca del seller.
- DDD/CQRS por capas; admin con textos en inglés + `lang/es.json`; nunca mostrar UUIDs en el admin (el
  `label_id` es un ULID de negocio, sí se muestra; el `node_id` de una impresora no).
- Regla de etiquetado de producto: nunca «bulto», nunca vacío; nombre + SKU del producto donde se muestre.

### 2. Estado actual

| Pieza | Dónde | Qué hace hoy |
|---|---|---|
| Payload | `packages/logistics/labels/src/Domain/ValueObject/LabelPayload.php` | `v=1`, `t=PKG`, `canonical()` = `LGL\|v\|t\|k\|id\|o\|ln\|sku\|u\|pc\|org\|dst\|ts`. `id` validado como ULID Crockford. |
| JSON del QR | `.../ValueObject/SignedLabel.php` (`toQrContent`/`fromQrContent`) | JSON compacto con 13 claves, **~250–260 bytes** (la `s` de modo abierto son 64 bytes en cero → 86 chars base64url, `Crypto/OpenLabelSigner.php`). `fromQrContent` rechaza cualquier `t` ≠ `PKG` y cualquier `v` ≠ 1. |
| Tipos | `.../ValueObject/LabelType.php` | `PKG` implementado; `MAN`, `NOD`, `VEH`, `DLV` reservados. |
| Simbología | `.../ValueObject/LabelSymbology.php`, `Infrastructure/Settings/SettingsLabelSymbologyProvider.php` | `qr \| barcode \| both` (default `both`), setting versionado `labels.symbology`; default en config `LABELS_SYMBOLOGY`. |
| Admin de formato | `packages/logistics/labels/src/Presentation/Filament/Configuration/LabelFormatSection.php` | Configuración › Labels › *Label format*: un único `Select` (native(false)) de simbología. La página vieja `Pages/LabelFormatSettings.php` solo redirige. |
| Render ZPL | `packages/logistics/labels/src/Infrastructure/Zpl/ZplLabelRenderer.php` | **Un solo layout fijo**: `^PW799 ^LL0609` (≈ 99,9 × 76 mm a 203 dpi). QR `^FO30,30 ^BQN,2,6 ^FDMA,<json>` (modelo 2, magnificación 6, ECC M). Texto a la derecha (x=360): SKU, Unit, Piece, From, To. Code128 `^FO30,470 ^BY2 ^BCN,90,Y,N,N ^FD<label_id>`. Sin `^MD`/`^PR`/`^PQ`. |
| Emisión | `Application/Service/LabelBatchEmitter.php`, `Command/EmitOrderLabels/EmitOrderLabelsHandler.php` (+ `EmitShipmentLabels`, `PreEmitOrderLabels`) | Firma → **renderiza el ZPL una vez** con la simbología vigente → persiste. Una etiqueta por pieza (`u`/`pc`). |
| Registro | migración `2026_10_13_001100_create_labels_emitted_table.php` | `labels_emitted(order_id, label_id unique, key_id, zpl text, signed_json text, created_at)` + `revoked_at/revoked_reason` (legado, ya no se usa). **El ZPL queda congelado** en la columna `zpl`. |
| API ZPL | `Presentation/Http/Controller/LabelController.php::zpl` (`GET /app/orders/{orderNumber}/labels/zpl`, `openapi/v1.yaml`) | Concatena el `zpl` **guardado**. Resolución por id: `resolveUnit` (`GET /app/labels/{labelId}/resolve`). |
| Preview admin | `resources/views/filament/labels/label-preview.blade.php`, `Presentation/Support/LabelPreviewRenderer.php` | Tarjeta blanca fija de 300 px: QR (Bacon, SVG) del `signed_json`, SKU, Code128 (picqer) del `label_id`, `label_id` en texto. No refleja el tamaño físico. |
| Hoja de impresión web | `app/Http/Controllers/Cockpit/LabelPrintController.php`, `resources/views/filament/labels/print-sheet.blade.php` | `?ids=…&printer=normal\|sticker`. La diferencia sticker/normal es **solo un texto** en la barra: mismo grid CSS (`minmax(260px,1fr)`), QR de 180 px, sin `@page size`. Registra `printed` en `label_events` con `meta.printer`. |
| Impresoras | migración `2026_11_11_000100_create_label_printers_table.php`, `Domain/Model/Printer.php`, `ValueObject/PrinterDetails.php`, `PrinterLanguage.php`, `Filament/Resources/PrinterResource.php` | `name, model, host, port(9100), language(zpl\|other), node_id, location, active`. **Sin dpi, sin ancho/alto de medio, sin perfil.** |
| Datos de producto | `packages/logistics/shipments/database/migrations/2026_10_13_000602_create_shipment_lines_table.php` (+ `…000604`) | `shipment_lines`: `sku, product_name, quantity, product_image_url, unit_price`. **Sin dimensiones, peso ni categoría.** `labels/Application/Dto/OrderLineInput.php`: `sku, name, quantity, piecesPerUnit` (siempre 1, `EloquentDispatchLinesReader`). Lo único dimensional es `planning_shipment_requirements.height_m/weight_kg`, **por envío**, no por línea. `sap-connector` (`MaterialMapping`) tampoco trae grupo de material ni medidas. |
| App: ZPL | `logistics-app/lib/core/printing/zpl_builder.dart` | Builder propio para preview/reimpresión offline: `^PW812 ^LL609`, QR `^FDLA,` (**ECC L**, distinto del backend que usa **M**), textos en español. Default `LabelSymbology.qr`. |
| App: impresión | `lib/core/printing/label_printer.dart`, `printer_config_data.dart`, `office/*` | `printLabel(zpl, render)`: ZPL crudo por BT/WiFi 9100/USB o raster para oficina. `PrinterConfig` sin dpi ni medio. |
| App: escaneo | `lib/core/scanning/scanner_screen.dart` (`kLabelScanFormats = [qrCode, code128]`), `lib/features/driver/presentation/driver_common.dart::verifyUnitLabel`, `lib/core/labels/label_resolver.dart` | Intenta `SignedLabel.fromQrContent`; si **no es JSON**, trata el texto leído como `label_id` y lo resuelve **online** (`/app/labels/{id}/resolve`). Sin red ⇒ rechazo. No hay caché offline de `label_id` por ruta. |

**Hallazgo clave para Lite:** la app **ya acepta hoy** un código cuyo contenido es el `label_id` desnudo
(es el camino del Code128). Un QR o Data Matrix que contenga solo el `label_id` cae en ese mismo camino sin
tocar el contrato del JSON — funciona online desde el día uno; solo falta el soporte offline.

### 3. Brechas

1. **Un solo layout** hardcodeado en `ZplLabelRenderer` (y otro distinto en `zpl_builder.dart`: `^PW799` vs
   `^PW812`, ECC M vs L, inglés vs español). No existe el concepto de *tamaño/perfil*.
2. **ZPL congelado al emitir** (`labels_emitted.zpl`): cambiar simbología o tamaño no afecta a etiquetas ya
   emitidas; el helper del admin dice «Reprint existing labels to apply it», pero la reimpresión sirve el ZPL
   guardado. Para Lite hace falta **render bajo demanda** (identidad persistida, presentación calculada).
3. **El QR completo no cabe bien en chico**: ~260 bytes ⇒ QR versión 12 (65 × 65 módulos) con ECC M.
4. **Code128 del ULID no cabe en 50 mm** (ver §4.2).
5. **Impresoras sin dpi ni medio cargado**: no se puede elegir el layout por impresora ni rutear un trabajo
   Lite a la impresora que tiene el rollo chico.
6. **Sin datos de producto** (dimensiones/categoría) para elegir el tamaño automáticamente.
7. **Hoja web sin tamaño físico**: «sticker» no fija `@page`; el preview no está a escala real.
8. **App offline ciega con id desnudo**: el camino `label_id` exige red (ADR 004 descartó «QR solo con ID»
   justamente por esto; con etiquetas abiertas el argumento de firma ya no aplica, pero el de offline sí).
9. **Insumo físico**: hoy no hay rollo de adhesivo removible ni medio < 2" definido.

### 4. Diseño propuesto

#### 4.1 Perfiles de tamaño (`LabelSizeProfile`)

Perfil = *cómo se dibuja* una etiqueta existente. 203 dpi = 8 dots/mm; 300 dpi = 11,8 dots/mm.

| Perfil | Medida | `^PW`×`^LL` 203 dpi | 300 dpi | Código 2D | Code128 | Texto | Uso |
|---|---|---|---|---|---|---|---|
| **Standard** | 100 × 75 mm (actual) | 799 × 609 (sin cambios) | 1181 × 886 | QR JSON completo (v12, mag 6 ⇒ 0,75 mm/módulo) | Sí (`^BY2`, ~80 mm) | SKU, unidad, pieza, origen, destino | Cajas, electro, línea blanca |
| **Lite** | 50 × 25 mm | 400 × 200 | 591 × 295 | QR con `label_id` (v2, 25 × 25) | No | SKU, nombre corto, `U n/m`, `label_id` | Celulares, accesorios, cajas chicas |
| **Lite 40×30** (variante) | 40 × 30 mm | 320 × 240 | 472 × 354 | QR o Data Matrix con `label_id` | No | SKU, nombre corto, `label_id` | Rollos de escritorio 40 mm |
| **Micro** (opcional, solo 300 dpi) | 30 × 15 mm | — (no recomendado) | 354 × 177 | Data Matrix 18 × 18 con `label_id` | No | SKU + últimos 6 del id | Joyería/cables; decisión abierta |

Los perfiles se modelan como VO de dominio (`final readonly`), con catálogo cerrado en código
(`standard | lite | lite_40x30 | micro`) y medidas/contenido parametrizables en settings (§4.4). El dpi no es
del perfil: es de la impresora; el renderer calcula dots = mm × dpi / 25,4.

#### 4.2 Qué contenido lleva el código en chico — evaluación

**Capacidad (ISO/IEC 18004, modo alfanumérico).** Los 32 caracteres Crockford del ULID (0-9, A-Z sin
I/L/O/U) están todos dentro del juego alfanumérico del QR ⇒ 26 chars caben en **QR versión 2 (25 × 25)** con
ECC **M** (cap. 38) e incluso **Q** (cap. 29). El JSON completo (~260 bytes, modo byte) exige **versión 12
(65 × 65)** con ECC M.

| Contenido | Módulos | 203 dpi mag 3 (0,375 mm) | 203 mag 4 (0,5 mm) | 300 dpi mag 4 (0,34 mm) | 300 mag 5 (0,42 mm) |
|---|---|---|---|---|---|
| `label_id` (QR v2) | 25 (+8 zona quieta) | 9,4 mm (12,4) | 12,5 mm (16,5) | 8,5 mm (11,2) | 10,6 mm (14,0) |
| `label_id` (Data Matrix 18×18) | 18 (+2) | 6,8 mm (7,5) | 9,0 mm (10,0) | 6,1 mm (6,8) | 7,6 mm (8,5) |
| URL corta `HTTPS://DTK.BO/L/<id>` (QR v3 alfanum.) | 29 (+8) | 10,9 mm | 14,5 mm | 9,8 mm | 12,3 mm |
| JSON completo (QR v12) | 65 (+8) | 24,4 mm (27,4) | 32,5 mm | 22,0 mm (24,7) | 27,5 mm |

Regla práctica para cámara de celular gama baja con ML Kit: módulo **≥ 0,375 mm** confiable, 0,33 mm
marginal, 0,25 mm no. ⇒

- **QR con solo `label_id`, ECC Q, 0,5 mm/módulo** (203 dpi mag 4): 12,5 mm + zona quieta ⇒ cabe holgado en
  50 × 25 y deja ~30 mm para texto. **Recomendado.** ECC Q tolera rayones/arrugas en caja chica.
- **JSON completo en 50 × 30 a 300 dpi mag 4** («Lite-Plus»): 22 mm, posible pero marginal y obliga a 300 dpi;
  solo si el dueño exige verificación offline sin red. Opción B.
- **Data Matrix**: el más compacto y el estándar de etiquetado de piezas chicas; ML Kit y lectores 2D lo leen.
  Requiere agregar `BarcodeFormat.dataMatrix` a `kLabelScanFormats`. Opción para 40×30 y Micro.
- **URL corta**: agrega un dominio público de tracking (y un punto de exposición) a cambio de que el cliente
  pueda escanear con la cámara del teléfono; útil a futuro, no necesaria. Decisión abierta.

**Code128 en chico.** Code128-B: 11 módulos por carácter ⇒ inicio + 26 × 11 + control + parada ≈ **321
módulos** + zonas quietas (10 + 10). A `^BY2`/203 dpi (0,25 mm) mide **~80 mm**; a `^BY1` (0,125 mm, barras
de 1 dot) ~40 mm pero ilegible para lectores láser comunes e inestable en térmica de 203 dpi; a `^BY2`/300 dpi
(0,17 mm) ~55 mm. ⇒ **Lite no lleva Code128.** No se inventa un código corto alternativo: sería una segunda
identidad (rompe «misma identidad»). Si `labels.symbology = barcode`, el perfil Lite **fuerza 2D** y el admin
lo advierte (decisión abierta §7).

#### 4.3 Impacto en el contrato QR

- **El QR JSON (`v=1`, `t=PKG`, `canonical()`) no cambia.** Standard sigue byte a byte igual (test de regresión
  contra el ZPL actual).
- El código Lite contiene el **`label_id` desnudo**, exactamente el mismo valor que hoy ya lleva el Code128 del
  Standard. Es un contrato que **ya existe** en la app (`verifyUnitLabel` → `resolveLabelId`), así que **no se
  sube `v`** ni se crea un `t` nuevo ni se regenera el vector.
- Se documenta en ADR 004 (enmienda) y en `openapi/v1.yaml`: «un código de etiqueta puede ser (a) el JSON PKG v1
  o (b) un `label_id` ULID de 26 chars; ambos resuelven la misma unidad».
- Alternativa descartada: JSON compacto `{"v":1,"t":"PKL","id":"…"}` — obliga a tocar `fromQrContent` en ambos
  repos, un tipo nuevo y vector nuevo, y engorda el QR (byte mode) sin aportar datos.
- Alternativa a decidir: prefijo `DT:` + id (30 chars, sigue en alfanumérico v2) para distinguir la etiqueta de
  cualquier otro Code128/QR de terceros. Si se adopta es un contrato nuevo (documentarlo; tampoco toca `v`).

#### 4.4 Cómo se elige el tamaño

Orden de precedencia (el primero que aplica gana), resuelto por un servicio de dominio puro
`LabelSizeResolver`:

1. **Elección al imprimir** (admin o app): «imprimir esta etiqueta en Lite». Siempre posible (reimpresión
   permitida, mismo id).
2. **Override por línea** guardado (admin, hub del pedido → acción *Change label size*): persiste el perfil
   sugerido de esas etiquetas; registra `label_events.event = 'resized'` con actor.
3. **Reglas configurables** (setting versionado `labels.size_rules`, sección nueva *Label sizes* en
   Configuración › Labels): lista ordenada de reglas `{match: sku_exact | sku_prefix | category, value,
   profile}`. Hoy solo es viable por **SKU exacto/prefijo** (no hay categoría ni medidas); el matcher de
   `category` y de dimensiones (`max_side_mm ≤ X ⇒ lite`) queda listo para cuando `inventory`/`sap-connector`
   traigan grupo de material o medidas (Fase 6). Nunca por propietario/seller.
4. **Perfil por defecto** (`labels.size_profiles.default = standard`).
5. **Medio de la impresora**: si la impresora destino solo tiene cargado un medio, el trabajo se renderiza para
   ese medio y el admin/app avisa si difiere del sugerido (no bloquea: el id es el mismo).

Persistencia: columna aditiva `labels_emitted.size_profile` (varchar, nullable = standard) con el perfil
**sugerido**; el ZPL deja de ser la fuente: se renderiza bajo demanda `(signed_json, perfil, dpi, simbología)`
y la columna `zpl` se mantiene con el Standard por compatibilidad (la app actual la sigue consumiendo).

#### 4.5 Impresoras y medios

- `label_printers`: columnas aditivas `dpi` (203|300|600, default 203) y `media_profile` (perfil del rollo
  cargado, default `standard`). `PrinterDetails` las valida; `PrinterResource` las edita con `Select`
  `->native(false)`; `ListAvailablePrinters`/`PrinterView` y el endpoint de impresoras las exponen (openapi
  aditivo). Una impresora = un medio cargado a la vez; una sede puede tener dos (4" para cajas, escritorio para
  Lite) y el trabajo se rutea por perfil.
- Verificar en ficha técnica (Fase 0): la ZQ630 Plus admite medio desde ~2" (≈ 51 mm) ⇒ Lite 50×25 sí, 40×30
  **no**; para 40 mm o 300 dpi hace falta una de escritorio (p. ej. ZD421/ZD621 300 dpi). Tras cambiar el rollo:
  calibrar (`~JC`) y fijar `^MNY` (gap).
- Insumo: rollo térmico directo con **adhesivo removible** (sale sin residuo; resuelve el «cliente quiere
  despegarla» y la reventa).

#### 4.6 Bocetos ZPL

ULID de ejemplo: `01JCQ8Z6R4T9M2XKBW7HNPVD3F`.

**Lite 50 × 25 mm, 203 dpi (recomendado)**

```zpl
^XA
^CI28
^PW400
^LL200
^LH0,0
^MNY
^PR2
^MD4
^FO8,14^BQN,2,4^FDQA,01JCQ8Z6R4T9M2XKBW7HNPVD3F^FS
^FO150,16^A0N,30,24^FB240,1,0,L^FDSM-A155FZKD^FS
^FO150,52^A0N,22,18^FB240,2,2,L^FDGalaxy A15 128GB Negro^FS
^FO150,108^A0N,26,24^FDU 1/1^FS
^FO150,140^A0N,18,14^FD...VD3F^FS
^FO8,172^A0N,18,12^FD01JCQ8Z6R4T9M2XKBW7HNPVD3F^FS
^XZ
```

QR v2 ECC Q, mag 4 ⇒ ~100 dots (12,5 mm) + zona quieta; `^PR2` (velocidad baja) y `^MD` moderado mejoran la
definición de módulos chicos. El `label_id` completo va legible (~39 mm a 12 dots de ancho) para carga manual
de respaldo.

**Lite 40 × 30 mm, 203 dpi, Data Matrix**

```zpl
^XA
^CI28
^PW320
^LL240
^LH0,0
^FO10,12^BXN,5,200,18,18^FD01JCQ8Z6R4T9M2XKBW7HNPVD3F^FS
^FO118,14^A0N,26,20^FB194,2,2,L^FDSM-A155FZKD^FS
^FO118,76^A0N,20,16^FB194,2,2,L^FDGalaxy A15 128GB^FS
^FO10,130^A0N,24,22^FDU 1/1^FS
^FO10,206^A0N,16,10^FD01JCQ8Z6R4T9M2XKBW7HNPVD3F^FS
^XZ
```

**Lite-Plus 50 × 30 mm, 300 dpi, JSON completo (opción B, offline total)**

```zpl
^XA
^CI28
^PW591
^LL354
^LH0,0
^FO12,12^BQN,2,4^FDMA,{"v":1,"t":"PKG","k":0,"id":"01JCQ8Z6R4T9M2XKBW7HNPVD3F",...}^FS
^FO300,24^A0N,36,30^FB280,1,0,L^FDSM-A155FZKD^FS
^FO300,72^A0N,26,22^FB280,3,2,L^FDGalaxy A15 128GB Negro^FS
^FO300,300^A0N,22,16^FD...VD3F  U 1/1^FS
^XZ
```

**Micro 30 × 15 mm, 300 dpi (opcional)**

```zpl
^XA
^PW354
^LL177
^FO10,14^BXN,8,200,18,18^FD01JCQ8Z6R4T9M2XKBW7HNPVD3F^FS
^FO170,24^A0N,30,24^FB176,2,2,L^FDSM-A155FZKD^FS
^FO170,120^A0N,26,20^FD...VD3F^FS
^XZ
```

(Micro solo lleva SKU: excepción a la regla «nombre + SKU» que el dueño debe aprobar.)

#### 4.7 Diseño en capas

- **Domain** (`labels`): `LabelSizeProfile` (enum + medidas), `PrintMedia` (dpi + perfil), `LabelSizeRule`,
  `LabelSizeResolver` (puro), contrato `LabelRenderer::render(SignedLabel, LabelSymbology, LabelSizeProfile,
  int $dpi)` con default Standard/203 para no romper llamadores.
- **Application**: `ChangeLabelSize` (comando, override por línea), `RenderLabels` (query: ids + perfil + dpi ⇒
  ZPL/SVG), `LabelSizeProfileProvider` (lee settings vía QueryBus, igual que `SettingsLabelSymbologyProvider`).
- **Infrastructure**: `ZplLabelRenderer` → un layout por perfil (`StandardZplLayout`, `LiteZplLayout`,
  `MicroZplLayout`), golden tests por perfil × dpi; Eloquent de las columnas nuevas.
- **Presentation**: sección `LabelSizeSection` en Configuración (perfiles + reglas, Repeater con `Select`
  native(false) y SKU buscable con datos reales); preview a **escala física** (CSS en mm) con selector de perfil;
  `print-sheet` con `?profile=` y `@page { size: 50mm 25mm; margin: 0 }` para impresora sticker, grilla de
  stickers en A4 para impresora normal; `LabelController::zpl` con `?profile=&dpi=` (default Standard).
  Strings nuevos en inglés + `lang/es.json`.

#### 4.8 Cambios en la app Flutter (`logistics-app`)

1. `kLabelScanFormats` (`lib/core/scanning/scanner_screen.dart`): agregar `dataMatrix` si se elige DM.
2. **Offline del id desnudo**: hoy `verifyUnitLabel` (`driver_common.dart`), `order_scan.dart`, `unit_scan.dart`,
   `carrier_scan_support.dart` exigen red para un `label_id`. Agregar un índice local `label_id → unit` al bajar
   la ruta/preparación/carga (los listados ya traen `label_id`: `order_preparation_screen.dart`) y resolver
   offline contra ese índice; online como hoy. Esto cierra la objeción de ADR 004 para Lite.
3. Validar el formato ULID antes de llamar a `/resolve` (evita resolver EAN/basura).
4. `zpl_builder.dart`: perfiles Lite/Standard para reimpresión offline, alinear con el backend (`^PW799`, ECC M,
   textos) o, mejor, pedir siempre el ZPL al backend con `?profile=` y dejar el builder solo como respaldo.
5. `PrinterConfig`: `dpi` y `mediaProfile` (de la lista de impresoras del backend o elegidos a mano); el diálogo
   de impresión ofrece *Standard / Lite* y preselecciona el sugerido.
6. `printable_label.dart`/`label_preview_sheet.dart`: vista Lite para impresoras de oficina.

### 5. Fases

| Fase | Entregables | Tareas | Criterios de aceptación | Pruebas | Esfuerzo | Depende de |
|---|---|---|---|---|---|---|
| **0. Prototipo físico y decisiones** | Matriz de escaneo v1, decisión de símbolo y medio | Enviar a mano (9100) los bocetos §4.6 a ZQ630 y a una 300 dpi; comprar rollos 50×25 / 40×30 con adhesivo removible; confirmar anchos mínimos de cada modelo | El dueño elige: QR-id vs DM-id vs JSON-completo, perfiles a soportar, Micro sí/no | Matriz §6 ronda 1 | 2–3 d + plazo de insumos | — |
| **1. Dominio + render** | `LabelSizeProfile`, layouts ZPL por perfil × dpi | VO/enum, refactor del renderer, contrato con defaults | Standard **byte-idéntico** al ZPL actual; Lite válido en Labelary/impresora | Golden ZPL por perfil × dpi; Pest unit; PHPStan 8; Pint | 3–4 d | F0 |
| **2. Registro, impresoras y API** | Columnas `size_profile`, `dpi`, `media_profile`; `?profile=&dpi=` | Migraciones aditivas idempotentes, VO `PrinterDetails`, `PrinterResource`, `LabelController`, openapi | Endpoints actuales sin cambio por defecto; ZPL Lite servido bajo demanda | Feature tests API; contrato openapi; test de migración aditiva | 3 d | F1 |
| **3. Selección automática + override** | Sección *Label sizes*, `LabelSizeResolver`, `ChangeLabelSize` | Settings versionados, reglas SKU, acción en hub del pedido, evento `resized` | Un SKU con regla Lite emite sugerido Lite; override cambia el perfil sin cambiar el id; nada se re-emite | Unit del resolver (precedencia); feature de emisión; bitácora | 3–4 d | F2 |
| **4. Admin: preview y hoja web** | Preview a escala, `print-sheet?profile=` | CSS mm, `@page`, grilla A4, selector de perfil, es.json | Lite impreso desde navegador a impresora sticker sale a 50×25 exactos y escanea | Render tests (`withoutVite`); prueba física desde Chrome | 2–3 d | F2 |
| **5. App** | Formatos, índice offline, perfil en impresión | §4.8 (1–6) en `logistics-app` | Escaneo Lite en preparación, carga y POD **sin red** reconoce la unidad; vector QR intacto | Tests Dart (resolver offline, builder); prueba en campo | 4–5 d | F2 (F3 para sugerido) |
| **6. Piloto y datos de producto** | Piloto en CD-SCZ-01 con celulares/accesorios; matcher por categoría/medidas | Ronda 2 de la matriz; KPIs; integrar grupo de material SAP/medidas cuando exista | ≥ 99 % lectura al primer intento en Lite durante 2 semanas; 0 unidades sin resolver por etiqueta | Matriz §6 ronda 2; tablero de scans fallidos | 1–2 sem calendario | F3–F5 |

Total estimado: **~3–4 semanas-persona** (backend ~2, app ~1) + calendario de insumos y piloto. DoD de cada
fase backend: Pest verde, PHPStan 8 limpio, Pint limpio y verificación real contra containers (CLAUDE.md).

### 6. Matriz de pruebas físicas de escaneo

Dimensiones a cruzar (no todas las combinaciones; F0 prioriza la diagonal recomendada):

- **Tamaño/perfil**: Standard 100×75 · Lite 50×25 · Lite 40×30 · Lite-Plus 50×30 · Micro 30×15.
- **dpi**: 203 (ZQ630 Plus) · 300 (escritorio).
- **Símbolo**: QR-id ECC M/Q · Data Matrix-id · QR JSON completo · Code128 (solo Standard).
- **Lector**: Android gama baja (cámara fija, ML Kit, modo continuo `continuous_scanner.dart`) · Android gama
  media · iPhone · imager 2D de almacén (handheld) · láser 1D (solo debe leer el Code128 del Standard).
- **Condición**: luz de almacén baja · film retráctil brillante · superficie curva/esquina · etiqueta arrugada ·
  distancia 10/20/30 cm · ángulo 0°/30°/45°.

Métricas por celda: 50 lecturas; **tasa al primer intento ≥ 99 %** (aceptación), tiempo medio de decodificación
< 800 ms en gama baja, y si hay verificador, grado ISO/IEC 15415 ≥ B (mínimo C). También: legibilidad humana del
`label_id` y del SKU, y que la etiqueta removible salga sin residuo de la caja del celular.

### 7. Riesgos y decisiones abiertas para el dueño

**Riesgos**

- **Ceguera offline** si Lite lleva solo el id y la app no tiene índice local (Fase 5 lo mitiga; mientras tanto
  Lite solo donde hay señal, p. ej. preparación en CD).
- **El cliente despega la etiqueta antes del POD** ⇒ el escaneo de entrega falla. Mitigar: POD por lista de la
  parada / ingreso de los últimos 6 del id (que está impreso) o pegar en la bolsa exterior.
- **Calidad de impresión** de módulos chicos a 203 dpi (cabezal gastado, `^MD` alto) ⇒ lecturas fallidas;
  requiere mantenimiento y `^PR` bajo.
- **ZQ630 no admite < ~51 mm**: 40×30 y Micro exigen otra impresora.
- **Simbología `barcode` global** incompatible con Lite (se fuerza 2D).
- **Divergencia app/backend** del ZPL (ya existe hoy: ancho, ECC, idioma); Lite la agrava si no se unifica.

**Decisiones abiertas**

1. Contenido del 2D Lite: `label_id` desnudo (recomendado) · prefijo `DT:` · URL corta pública · JSON completo
   a 300 dpi.
2. Símbolo: QR (recomendado, cero cambio en la app online) vs Data Matrix (más chico, agrega formato).
3. Perfiles a soportar en la v1: solo Lite 50×25, o también 40×30 y Micro.
4. Excepción a «nombre + SKU» en Micro (solo SKU).
5. Cómo se elige por defecto mientras no haya categoría/medidas: lista de SKUs/prefijos mantenida por operación,
   o pedir a SAP el grupo de material.
6. Compra de impresora de escritorio 300 dpi por sede y de rollo con adhesivo removible.
7. Si la etiqueta Standard se sigue imprimiendo además de la Lite (p. ej. Standard en la bolsa/caja exterior y
   Lite en el producto: mismo id, dos copias) — permitido por la regla de reimpresión.

---
