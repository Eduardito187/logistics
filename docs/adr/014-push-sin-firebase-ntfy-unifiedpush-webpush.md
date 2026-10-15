# ADR 014 — Push sin Firebase: ntfy/UnifiedPush + Web Push (VAPID)

**Status:** Accepted (revisa el ADR 006 en su capa de push: reemplaza FCM/Firebase)
**Fecha:** 2026-10-13
**Autores:** @eduard
**Deciders:** Eduard Huallata

## Contexto

El ADR 006 apoyaba las notificaciones push en **FCM (Firebase)**, tanto para la app de clientes
(topics por envío) como para el web push de la página de tracking, por ser "gratis". Firebase es de
Google: la regla transversal del dueño (ver ADR 013) es **cero Google y cero servicios de pago**, así
que FCM queda descartado como transporte de push. Hace falta un camino de push que sea 100%
open-source y auto-hospedable, tanto para el navegador (clientes sin app) como para la app interna de
operación (`logistics-app`, choferes/preparadores).

## Decisión

El push se separa en dos transportes, ambos sin Firebase:

- **Web Push con VAPID (estándar W3C)** para el navegador. Es el `Push API` nativo: el servidor firma
  los envíos con un par de claves **VAPID** propias y hace `POST` al endpoint que entrega el navegador
  (Chrome, Firefox, Edge, Safari 16.4+). **No requiere proyecto Firebase ni API key**: solo las claves
  VAPID (`WEBPUSH_VAPID_PUBLIC_KEY` / `WEBPUSH_VAPID_PRIVATE_KEY` / `WEBPUSH_VAPID_SUBJECT`). Es el
  canal de la página pública de tracking (reemplaza el "web push FCM" del ADR 006).
- **ntfy + UnifiedPush** para la app interna Android. **ntfy** es un servidor pub-sub de notificaciones
  **open-source y self-host** (`NTFY_URL`); **UnifiedPush** es el estándar que permite a la app recibir
  push a través de un distribuidor (ntfy) **sin Google Play Services**. La app se suscribe a un topic
  por dispositivo/rol y la plataforma publica por HTTP al topic, sin conocer tokens de Google.

La elección de canal por tipo de mensaje sigue siendo **configuración versionada** (ADR 006/010) y el
envío sigue saliendo por **outbox** (ADR 008): nada de HTTP síncrono en el request.

## Alternativas consideradas

### Mantener FCM/Firebase (ADR 006)
- Pros: ya estaba previsto; infraestructura de Google "gratis".
- Contras: es Google y atado a su consola/service account; **lo prohíbe la regla del dueño**.

### Solo Web Push (VAPID) también para la app interna
- Pros: un solo mecanismo.
- Contras: Web Push es del navegador; para una app nativa Android que debe recibir push en segundo
  plano de forma fiable, UnifiedPush/ntfy es el camino correcto.

### Servidor de push propio ad-hoc (long-polling/WebSocket permanente)
- Pros: control total.
- Contras: reinventar entrega fiable, reintentos y ahorro de batería que ntfy/UnifiedPush ya resuelven.

## Consecuencias

### Positivas
- Push 100% open-source y self-host; sin Firebase, sin cuentas de Google, sin costo por mensaje.
- La plataforma no guarda tokens de FCM; publica a endpoints VAPID y a topics de ntfy.
- Web Push VAPID es estándar y funciona en los navegadores modernos sin SDK propietario.

### Negativas / trade-offs aceptados
- **iOS nativo** no admite push sin APNs/Apple; la app interna de operación se asume **Android**
  (flota propia, dispositivo gestionado), y para clientes iOS el camino es Web Push (Safari 16.4+,
  agregando la web a la pantalla de inicio) con respaldo de email.
- Operar un servidor **ntfy** propio (healthcheck, retención, auth de topics) es infraestructura nueva.
- La entrega UnifiedPush en Android stock pasa por el distribuidor ntfy instalado; hay que documentarlo
  en el onboarding de dispositivos de la operación.

### Neutras
- La arquitectura de la capa de notificaciones no cambia: push sigue siendo un canal de la cascada del
  ADR 006; solo cambia su transporte (FCM → VAPID / ntfy+UnifiedPush).

## Referencias

- Revisa el ADR 006 (reemplaza FCM/Firebase por Web Push VAPID + ntfy/UnifiedPush).
- ADR 013 (todo open-source self-host, sin Google ni pago): esta decisión es su corolario en push.
- ADR 008 (outbox): envío asíncrono con reintentos e idempotencia.
- Roadmap: C-NOT-01, I-11 (reescritos a VAPID/ntfy, sin Firebase), S-11, F0-06.
- Web Push API + VAPID (RFC 8292), ntfy (`ntfy.sh`, self-host), UnifiedPush.
