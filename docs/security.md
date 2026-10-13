# Seguridad y datos personales — logistics

Prácticas de seguridad del backend logístico: **minimización de PII**, endurecimiento de la
**página pública de rastreo**, **rotación de claves** de firma de etiquetas, **secret scanning**
y el **catálogo de datos personales** con su política de retención.

Complementa `docs/architecture.md`, `docs/deploy.md` (retención de objetos en S3) y los ADR
002 (fuera de SAP), 004 (etiquetas firmadas) y 006/010 (notificaciones). El respaldo y la
restauración de la base viven en `docs/backup-restore.md`.

> Principio rector: **recolectar el mínimo PII, exponer aún menos y borrar lo que ya no sirve.**
> La plataforma es un sistema de ejecución logística, no un CRM: los datos del cliente entran
> solo para entregar el pedido y notificarlo.

---

## 1. Minimización de PII

### 1.1 Las etiquetas no llevan PII (ADR 004)

El QR de cada bulto es un JSON compacto firmado Ed25519 con claves cortas
(`v,t,k,id,o,ln,sku,u,pc,org,dst,ts,s`). **No contiene nombre, teléfono ni dirección**: solo
ids internos, SKU y numeración de unidades/piezas. Una etiqueta caída en la calle no filtra
datos del cliente ni permite fabricar otra (la firma se verifica offline). Esta regla es un
contrato con la app Flutter: si el formato cambiara, se sube `v` y se regenera el vector de
prueba (ver CLAUDE.md § Etiquetas QR).

### 1.2 PII en pedidos, rastreo y notificaciones

El PII se concentra en tres lugares y se trata distinto en cada uno:

- **Pedidos (`shipments`)** — es el dueño del dato de contacto y entrega: dirección,
  coordenadas y confianza de la dirección (`AddressConfidence`). Es PII operativo: necesario
  para rutear y entregar. No se expone en ninguna respuesta pública.
- **Rastreo (`tracking`)** — guarda a propósito **cero PII**: el modelo `ShipmentTracking`
  referencia el envío por id y nunca persiste nombre ni dirección. La vista pública
  (`PublicTrackingView`) no expone id de envío, id de seguimiento, token, nombre ni dirección:
  solo estado, paradas restantes, ETA y última posición del camión.
- **Notificaciones (`notifications`)** — email y teléfono son **contactos de respaldo** de la
  cascada (ADR 006/010: Push → WebPush → Email → SMS/WhatsApp/Telegram). Son el PII más
  sensible del sistema; viven en la notificación el tiempo que dura su ciclo y se purgan por
  retención (§5). Nunca se registran en logs (ver §1.4).

### 1.3 Retención y borrado

Toda tabla con PII tiene una ventana de retención (§5). El borrado es por **tarea del
scheduler** que purga registros vencidos; como todo I/O y proceso de fondo, corre por colas,
nunca dentro de un request de usuario (ADR 002). La evidencia con valor legal (POD, checklist,
daños) se retiene por años en S3 con bloqueo de borrado (ver `docs/deploy.md`), y es la única
excepción a "borrar lo que ya no sirve".

### 1.4 PII fuera de logs y monitoreo

- Sentry arranca con `SENTRY_SEND_DEFAULT_PII=false`: no manda IP, cookies ni datos de usuario
  (ver `docs/observability.md`).
- Los contactos de respaldo (email/teléfono) y las direcciones **no** se escriben en los logs
  de aplicación. Al depurar notificaciones se registra el id de la notificación y el canal, no
  el destinatario.
- Los respaldos de base **sí** contienen PII: se cifran en reposo y se tratan como dato
  sensible (ver `docs/backup-restore.md` § cifrado y acceso).

---

## 2. Endurecimiento de la página pública de rastreo

La página pública (`GET /api/v1/channels/tracking/{token}` y `.../units`) la abre cualquiera con
el enlace, sin autenticación. Su seguridad descansa en el token y en los límites de la puerta.

### 2.1 Token no adivinable

`TrackingToken` (dominio de `tracking`) se genera con `random_bytes(32)` (CSPRNG) y se codifica
en **base64url** (43 caracteres, ~256 bits de entropía). No se deriva del id del envío ni de
nada predecible: es la clave pública del seguimiento. Al reconstruirlo desde la URL se valida
forma (`[A-Za-z0-9_-]+`) y longitud (32..64), y las comparaciones usan `hash_equals`
(tiempo constante). La ruta restringe el parámetro con `->where('token', '[A-Za-z0-9_-]+')`.

### 2.2 Rate limit

La ruta vive bajo la puerta `channels`, con el limiter `channels` (ver
`AuthServiceProvider::registerRateLimiters`). Cada canal trae su propio tope por minuto; si el
canal aún no se resolvió, cae a un tope conservador por IP (30/min). Esto frena el barrido de
tokens por fuerza bruta. **Recomendación:** mantener el tope del canal público de tracking bajo
(p. ej. 60/min por IP) y vigilar los 404 masivos como señal de escaneo.

### 2.3 404 genérico (no enumeración)

Un token que no corresponde a ningún seguimiento devuelve **404 genérico**: no revela si el
token existe pero expiró, ni ninguna otra pista. Así no se puede distinguir "no existe" de "no
autorizado".

### 2.4 Expiración del enlace (pendiente)

Hoy el token no expira. **Recomendado** (roadmap S-07): darle vigencia al enlace y dejar de
servirlo un tiempo después de entregado/fallido el envío (p. ej. 30 días). Al implementarlo,
seguir devolviendo 404 genérico cuando esté vencido, para no delatar la causa.

### 2.5 Sin claves internas en la respuesta

La vista pública nunca incluye ids internos, token, ni claves. El mapa usa Leaflet +
OpenStreetMap sin API key, así que no hay secretos del lado del cliente.

---

## 3. Rotación de las claves de firma de etiquetas

Las etiquetas se firman con Ed25519 (ADR 004). El campo `k` del QR identifica la clave, lo que
permite **rotar sin invalidar etiquetas ya impresas**.

Variables (ver `packages/logistics/labels/README.md` y `config/logistics-labels.php`):

| Variable | Rol |
| --- | --- |
| `LABELS_ACTIVE_KEY_ID` | Id (`k`) de la clave con la que se firman las etiquetas **nuevas** |
| `LABELS_SIGNING_KEY` | Clave privada Ed25519 activa (base64). **Secreto; solo en el servidor** |
| `LABELS_PREVIOUS_PUBLIC_KEYS` | Claves públicas anteriores aún aceptadas: `id:base64,id:base64` |

### Procedimiento de rotación

1. Generar el par nuevo con id incremental:
   `docker compose exec -T app php artisan logistics:labels:keygen --key-id=<N>`
   (imprime las líneas para `.env` y la clave pública; **no escribe en disco**).
2. Mover la clave **pública anterior** (su `id:base64`) a `LABELS_PREVIOUS_PUBLIC_KEYS` para
   que las etiquetas ya impresas sigan verificándose.
3. Poner la nueva privada en `LABELS_SIGNING_KEY` y subir `LABELS_ACTIVE_KEY_ID=<N>`.
4. Redesplegar (el env se inyecta por secretos del orquestador, nunca en la imagen).
5. Exportar el bundle de públicas para la app con
   `php artisan` (comando `ExportPublicKeysCommand`) y distribuirlo; la app verifica offline
   contra todas las públicas que conoce.

### Cuándo rotar

- Sospecha de filtración de `LABELS_SIGNING_KEY`.
- Rotación periódica preventiva (p. ej. anual).
- **Nunca** se borra una pública anterior mientras existan etiquetas impresas con ese `k` en
  circulación; recién se retira cuando se da por vencido ese lote.

### Buenas prácticas

- La privada solo vive en el env del servidor; jamás en el repo, en la app ni en logs.
- La app trae **solo públicas** (seguras de distribuir).
- Si el **formato** del QR cambia (no la clave), es otro procedimiento: subir `v` y regenerar
  el vector de prueba (CLAUDE.md § Etiquetas QR).

---

## 4. Secret scanning y manejo de secretos

### 4.1 Nada de secretos en el repo

- `.env` **nunca** se commitea; el env real se inyecta por el orquestador (ver `docs/deploy.md`).
- Secretos del proyecto: `APP_KEY`, `LABELS_SIGNING_KEY`, credenciales de PostgreSQL/Valkey/S3,
  DSN de Sentry, credenciales SAP/FCM/SMS/WhatsApp/Telegram, tokens de integración de `access`.
- Las claves de integración (`access`) se guardan **hasheadas**, nunca en claro.

### 4.2 Escaneo recomendado (pendiente de cablear)

Aún no hay secret scanning automático en CI. Se recomienda:

- **Pre-commit** con `gitleaks` (o `trufflehog`) para frenar un secreto antes de que entre al
  historial.
- **En CI** un job `gitleaks detect` sobre el push/PR, que falle el pipeline ante un hallazgo.
- Si un secreto llega a commitearse: **rotarlo** (no basta con borrar el commit) — rotar la
  clave afectada por su procedimiento (p. ej. §3 para etiquetas) y reescribir historial solo
  como medida secundaria.

---

## 5. Catálogo de datos personales y política de retención

Inventario del PII que toca la plataforma, dónde vive y cuánto se conserva. Las ventanas de
borrado automático son la política objetivo; la evidencia legal es la excepción (se conserva).

| Dato | Contexto / almacén | Sensibilidad | Retención objetivo | Borrado |
| --- | --- | --- | --- | --- |
| Dirección y coordenadas de entrega | `shipments` (PostgreSQL) | Alta | Vida del pedido + ventana de disputa | Purga por scheduler al vencer |
| Confianza de dirección (`AddressConfidence`) | `shipments` | Media | Igual que la dirección | Con el pedido |
| Email de contacto (respaldo) | `notifications` | Alta | Vida de la notificación | Purga por scheduler |
| Teléfono de contacto (respaldo, SMS) | `notifications` | Alta | Vida de la notificación | Purga por scheduler |
| Chat id de Telegram | `notifications` | Media | Vida de la notificación | Purga por scheduler |
| Firma y fotos del cliente (POD) | S3 disco `pod` | Alta (biométrico/firma) | ~2 años (evidencia legal) | Sin borrado automático; bloqueo de borrado |
| Checklist de carga/salida | S3 disco `checklist` | Baja–Media | ~2 años | Sin borrado automático |
| Evidencia de daños | S3 disco `damage` | Media | ~5 años (siniestros) | Sin borrado automático |
| Posición GPS del chofer/camión | `telemetry` (PostgreSQL) | Media (ubicación de trabajador) | Ventana operativa corta | Purga por scheduler |
| Token de rastreo público | `tracking` | Baja (no PII, pero da acceso) | Vigencia del enlace (ver §2.4) | Expira con el seguimiento |
| Usuarios del admin (nombre, email) | `access` (PostgreSQL) | Media | Mientras la cuenta esté activa | Al dar de baja la cuenta |

Notas:

- **Etiquetas:** no figuran en el catálogo porque **no llevan PII** (§1.1).
- **Rastreo público:** no persiste PII; el token da acceso al estado, no a datos del cliente.
- **Respaldos:** replican el PII de las tablas; su ciclo de vida y cifrado están en
  `docs/backup-restore.md`. Un borrado por retención en la base no alcanza a los respaldos ya
  tomados hasta que esos respaldos caduquen por su propia rotación.
- Los plazos exactos (ventana de disputa, vida de la notificación) se fijan con Legal/Negocio;
  esta tabla define el **mecanismo** y el orden de magnitud.
