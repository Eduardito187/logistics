# Reporte backend — respuesta a los 4 pedidos de la app (2026-10-16)

**De:** backend `logistics` · **Para:** equipo de la app interna (logistics-app)
**Fecha:** 2026-10-16

Resumen de estado de los cuatro pedidos recibidos en `docs/backend-requests/`:

| # | Pedido | Estado |
|---|--------|--------|
| 4 | Tienda asignada al usuario (`user.node` + 403) | ✅ **Entregado** (previo) |
| 2 | Listado de envíos filtrado por la tienda del usuario | ✅ **Entregado** (con nota de ingest) |
| 3 | Push FCM hacia la app interna | ✅ **Entregado** (endpoint + capacidad; cableado 1-a-1 con nota) |
| 1 | Datos para la pantalla "Entrega en curso" del chofer | ✅ **Entregado** (6 ptos; el #6 queda opcional) |

**Verificación global (DoD), ejecutada en los containers:**

- **Tests:** `1833 passed` · `4930 assertions` (suite Pest completa).
- **Cobertura:** `84.4 %` (gate `--min=75` superado).
- **PHPStan nivel 8:** `No errors` (2284 archivos).
- **Pint:** limpio (2650 archivos).
- **Rutas nuevas registradas** (verificado con `route:list`):
  `POST api/v1/app/deliveries/{shipmentId}/issues`, `PUT api/v1/app/devices/current/push-token`.
- **Contrato:** `openapi/v1.yaml` actualizado en el mismo cambio; `ApiContractTest` (Spectator sobre
  `v1.yaml`) en verde.

> **Importante (Doc 2):** el modelo `Shipment` no tenía vínculo con el nodo destino. Se agregó el campo
> `destination_node_id` de forma **aditiva y nullable**. El filtrado y el 403 ya funcionan; mientras el
> **ingest/SAP no pueble ese campo**, un usuario de tienda verá su lista **vacía** (nunca envíos de otras
> tiendas). Ver detalle en el punto 2.

---

## Punto 4 — Tienda asignada al usuario del rol tienda ✅

Ya estaba entregado en la iteración anterior; se confirma íntegro:

- `GET /app/auth/me` → `AppUserProfile.node` (Node completo: `id, code, name, type, lat, lng, status,
  department`), **nullable** para roles sin tienda. En `openapi/v1.yaml` con el patrón 3.1
  `oneOf: [$ref Node, {type: 'null'}]`.
- Asignación usuario→nodo: `users.node_id` (UUID de `network_nodes`); `App\Models\User::assignedNode()`.
- Autorización server-side: middleware `EnsureStoreNodeScope` → **403** RFC 7807
  (`This action is restricted to your assigned store node.`) cuando un rol Tienda opera sobre un nodo
  distinto al suyo, aplicado en los endpoints de recepción:
  `POST /app/node-receptions`, `GET /app/node-receptions/{shipmentId}/{nodeId}`,
  `POST /app/node-receptions/units`, `POST /app/deliveries/scans/node-reception`,
  `POST /app/node-receptions/reconcile`.
- Admin Filament: recurso para asignar la tienda del usuario (`StoreUserResource`).

---

## Punto 2 — Listado de envíos filtrado por la tienda del usuario ✅

### Qué se hizo

1. **Nodo destino en el envío (aditivo, nullable).** Nueva columna `shipments.destination_node_id`
   (UUID, nullable, indexada), migración **idempotente** (`if (!Schema::hasColumn(...))`). Propagada al
   agregado de dominio `Shipment` (último parámetro con default `null`, sin romper llamadas existentes),
   al `ShipmentMapper`/`ShipmentRecord`, al read model `ShipmentView` (`destination_node_id`) y a la
   factory. El comando `ReceiveShipment` acepta `destinationNodeId` opcional para que **el ingest/SAP lo
   pueble** sin más cambios de dominio.
2. **Filtro server-side forzado para rol Tienda.** `GET /app/shipments`
   (`ListShipmentsController`): si el usuario es rol `Tienda`, se **fuerza**
   `destinationNodeId = users.node_id` e **ignora** cualquier parámetro del cliente (no se confía en la
   app). Otros roles pueden pasar el filtro opcional `destination_node_id` o ninguno. También se agregó
   el parámetro opcional al `Query`/`Handler` de `ListShipments`.
3. **POD de recojo en tienda con scope de nodo.** `RecordProofOfDeliveryController`: si el usuario es
   rol Tienda, se lee el `destination_node_id` del envío y se responde **403** RFC 7807 si no coincide
   con su nodo (o si el envío aún no tiene nodo). Otros roles pasan. Se resolvió **en el controller**,
   sin tocar rutas.
4. **openapi:** `Shipment.destination_node_id` (nullable, `oneOf`) y query param `destination_node_id`
   en `GET /app/shipments`.

### Nota de operación (clave)

El vínculo envío→nodo no existía antes de la recepción (el único, `execution_node_reception_units`,
nace **en** la recepción). Por eso el nuevo campo es nullable y lo debe poblar el ingest/SAP con la
**tienda destino del pedido**. Comportamiento mientras tanto, **seguro por diseño**:

- Un envío con `destination_node_id = null` **nunca** matchea el filtro forzado de una tienda ⇒ la tienda
  **no ve envíos de otras tiendas** (exactamente lo que pedía el doc). Si todavía no hay dato, ve lista
  vacía.
- En el POD, si el envío no tiene nodo, una tienda **no puede probar que es suyo** ⇒ 403.

### Para la app

Si solo cambia el filtrado server-side (punto 1), la app **no necesita cambios**. Con el campo nuevo
(`destination_node_id`) se regenera el cliente desde `openapi/`.

---

## Punto 3 — Push FCM hacia la app interna ✅

### Qué se hizo

1. **`PUT /app/devices/current/push-token`** (Bearer `/app/*`). Body `{ "push_token": string|null }`
   (string = Firebase Installation ID / FID; `null` = baja del push). Resuelve el **dispositivo actual**
   desde el `device_id` del token Sanctum (igual que `EnsureDeviceToken`) y actualiza solo ese device del
   usuario. Respuesta `204`; `404` si el token no está ligado a un device; validación `max:512`.
   Idempotente por ser PUT. En `openapi/v1.yaml`.
2. **Destino por dispositivo en FCM (capacidad lista).** `FcmChannelSender::sendToDevice(...)` publica a
   un token FCM (`"token": <fid>`) con el **mismo payload** que el envío por topic:
   `notification {title, body}` + `data {notification_id, shipment_id, message_type}` +
   `android.priority=high`, mismo endpoint `messages:send` y mismo `FCM_PROJECT_ID`. Cubierto con test
   (`Http::fake` verifica `message.token` y no `topic`).
3. **Payload y proyecto (puntos 4 y 5 del doc):** se cumplen con la implementación FCM existente
   (verificado): mismo shape de `notification`/`data`, mismo `FCM_PROJECT_ID`/`FcmAccessToken`.

### Pendiente consciente (no rediseñamos el dominio)

El modelo de dominio `Notification` hoy apunta a `shipmentId` + `Recipient` (email/phone/telegram) y
expone `fcmTopic()` = `shipment_<id>`; **no** lleva usuario/dispositivo. Por eso el envío 1-a-1 a un
chofer/tienda concreto (vía `sendToDevice`) queda como **capacidad disponible pero no cableada** al flujo
del outbox. Para cablearlo haría falta: (1) un destinatario de tipo usuario/dispositivo (o un tipo de
notificación "interna"), (2) un resolver usuario → `Device(push_token)` → tokens, y (3) que el relay del
outbox invoque `sendToDevice` por cada token (fan-out, idempotente por `event_id`). Es un cambio de
diseño del contexto `notifications`, fuera del alcance de este pedido.

### Recomendación (punto 3 opcional — topics internos)

Para difusión por grupo sin mantener push_tokens: topics FCM `role_chofer`, `role_supervisor`,
`node_<id>`. La app ya conoce roles y nodo (`auth/me`) y tiene `subscribeToTopic`; el backend solo
publicaría con `'topic' => "role_chofer"` reutilizando el sender. Ventaja: FCM gestiona la membresía, sin
fan-out ni limpieza de tokens muertos. Para 1-a-1 conviene `sendToDevice`; para broadcast por rol/nodo,
topics. No implementado por ser opcional y requerir decidir el formato de topic + la suscripción en la
app.

---

## Punto 1 — Datos para la pantalla "Entrega en curso" del chofer ✅

### Qué se hizo (los 5 puntos del doc; el #6 queda opcional)

1. **Permiso del chofer sobre `GET /app/shipments/{shipment}`: ya cubierto, sin cambios.** La puerta
   `app` usa `['auth:sanctum','device','idempotency']` sin gate de rol/ability por ruta, y la ruta no
   añade restricción; cualquier chofer con token de usuario+dispositivo vigente puede leerla.
   `ShowShipmentController` → `ShipmentView` ya expone `customer_phone` y las líneas (con `image_url`).
2. **`DriverRouteStop` enriquecido** (evita una llamada por parada; sirve desde la ruta cacheada):
   - `customer_phone` (nullable) — columna real `shipments.customer_phone`.
   - `address_reference` (nullable).
   - `items`: `[{ sku, name, quantity, image_url? }]` (siempre presente; vacío si no hay dato).
   El enriquecimiento es cross-context por **lectura directa en lote** (`whereIn`) de `shipments` y
   `shipment_lines`, en Application/Infrastructure de `execution`, **sin acoplar el dominio** (los
   agregados `RouteStop`/`VehicleRoute` no lo conocen). No se cachean objetos; todo es primitivo.
3. **`image_url` del producto:** ya existía en `ShipmentLineView`; se propaga en `items[].image_url`
   (desde `shipment_lines.product_image_url`). Además se alineó el schema `ShipmentLine` del openapi
   (tenía drift: le faltaban `image_url`/`price`/`currency` que el read model ya devolvía).
4. **`route_geometry` en `DriverRoute`** (GeoJSON LineString `[lng,lat]`, schema `RouteGeometry`
   reutilizado, nullable). Se vincula ruta↔plan por `(vehicle_id, route_date)` ↔
   `planning_plans.(vehicle_id, plan_date)` (ambas con unique por ese par ⇒ vínculo fiable). Es `null`
   si no hay plan para ese vehículo+día o el plan aún no tiene geometría calculada (la app dibuja la línea
   directa mientras tanto, como ya hace).
5. **`POST /app/deliveries/{shipmentId}/issues`** — reporte de inconveniente que **no cierra** la entrega
   (distinto de "entrega fallida" y del incidente de vehículo). Body
   `{ type: DELAY|ACCESS|CUSTOMER_REQUEST|ADDRESS_UNCLEAR|OTHER, note?, lat?, lng?, occurred_at,
   photo_path? }`. **Idempotente** por header `Idempotency-Key` (o `idempotency_key` en el body como
   fallback). CQRS completo: comando `ReportDeliveryIssue` + handler, enum `DeliveryIssueType`, agregado
   append-only `DeliveryIssue` (UUID v7), evento de dominio `DeliveryIssueReported` emitido **por
   outbox** (notifica a la torre de control / cliente sin I/O síncrono), tabla
   `execution_delivery_issues` (migración aditiva idempotente, `idempotency_key` único). Respuesta `202`.
   En `openapi/v1.yaml` (`DeliveryIssueRequest` + `DeliveryIssueType`).
6. **Foto obligatoria del POD (opcional del doc): no implementado.** Exigir `photo_path` en
   `ProofOfDeliveryRequest` solo para el rol chofer no es trivial (distinguir rol dentro del request/
   handler + versionar contrato). La app ya la exige del lado cliente; queda como refuerzo opcional
   pendiente si el backend lo quiere endurecer.

### Contrato (openapi)

`DriverRouteStop` (+`customer_phone`, `address_reference`, `items`), `DriverRoute` (+`route_geometry`),
`ShipmentLine` (+`image_url`, `price`, `currency`), nuevo path `POST /app/deliveries/{shipmentId}/issues`
y schemas `DeliveryIssueRequest`/`DeliveryIssueType`.

---

## Qué debe hacer la app

- **Regenerar el cliente** desde `openapi/v1.yaml` (cambia el contrato en los 4 frentes).
- **Doc 2:** sin cambios si solo usa el filtrado server-side; coordinar con el equipo de ingest/SAP para
  que los pedidos traigan su **tienda destino** → poblar `destination_node_id` (hoy la lista de tienda
  sale vacía hasta que exista ese dato).
- **Doc 3:** empezar a llamar `PUT /app/devices/current/push-token` cuando FCM rote el FID; mandar el FID
  en `device.push_token` del login (ya lo hace). Si se prefiere el *registration token* clásico en vez de
  FID, avisar (es un cambio menor en el backend). Definir si el push interno será por dispositivo
  (requiere el cableado descrito) o por topics de rol/nodo (recomendado para broadcast).
- **Doc 1:** consumir `customer_phone`/`address_reference`/`items`/`route_geometry` desde la ruta;
  enganchar "Ayuda → Reportar un inconveniente" al nuevo `POST .../issues` (encolar offline con
  `Idempotency-Key`).
