# ADR 014 — Push por Firebase Cloud Messaging (FCM)

**Status:** Accepted (decisión del dueño, 2026-10-16; reemplaza al transporte de push self-host anterior)
**Fecha:** 2026-10-16
**Autores:** @eduard
**Deciders:** Eduard Huallata

## Contexto

Una versión previa de este ADR había movido el push de la app fuera de Firebase hacia un transporte
self-host, buscando un stack 100% open-source. En la práctica ese camino sumaba infraestructura nueva
que operar (servidor de push propio, healthcheck, retención, auth de topics, un distribuidor en cada
dispositivo) y entrega poco fiable en segundo plano en Android stock, sin un beneficio real para la
operación.

El dueño decide **volver a Firebase Cloud Messaging (FCM)** como transporte de push de la app. FCM es
el camino estándar y fiable para entrega en segundo plano en Android, ya integrado con la app Flutter
(`firebase_messaging`), y no exige operar infraestructura de push propia.

## Decisión

El push de la app se publica por **Firebase Cloud Messaging (FCM)**:

- **Topic por envío.** La plataforma publica al topic `shipment_<id>`; la app Flutter se suscribe al
  topic del envío que le interesa. La plataforma **no guarda tokens de dispositivo**: publica al topic
  y FCM resuelve la entrega.
- **API HTTP v1 con service account.** El envío usa la **API HTTP v1** de FCM, autenticada con una
  **service account** de Google (credenciales en `FCM_CREDENTIALS`, proyecto en `FCM_PROJECT_ID`). No
  se usa la API legacy de server key.
- **App Flutter con `firebase_messaging`.** La app interna de operación y la de clientes reciben el
  push vía el SDK `firebase_messaging`, con el manejo nativo de Firebase en Android.

La elección de canal por tipo de mensaje sigue siendo **configuración versionada** (ADR 006/010) y el
envío sigue saliendo por **outbox** (ADR 008): nada de HTTP síncrono en el request de usuario.

La cascada de notificaciones por defecto es **FCM → Telegram → Email → SMS**: el push FCM es el primer
canal y escala a los demás cuando no hubo apertura.

## Alternativas consideradas

### Mantener el transporte de push self-host anterior
- Pros: sin dependencia de Google; 100% open-source.
- Contras: infraestructura nueva que operar (servidor de push, distribuidor por dispositivo),
  entrega en segundo plano menos fiable en Android stock. El costo operativo no se justificó.

### Servidor de push propio ad-hoc (long-polling / WebSocket permanente)
- Pros: control total.
- Contras: reinventar entrega fiable, reintentos y ahorro de batería que FCM ya resuelve.

## Consecuencias

### Positivas
- Entrega de push fiable en segundo plano en Android, el camino estándar del ecosistema.
- La plataforma no opera infraestructura de push propia ni guarda tokens de dispositivo (publica por
  topic).
- Integración directa con la app Flutter vía `firebase_messaging`, sin SDKs ni distribuidores extra.

### Negativas / trade-offs aceptados
- Dependencia de **Firebase/Google** para el transporte de push (la service account vive en
  `FCM_CREDENTIALS`), a contramano de la meta general de stack self-host. Se acepta por fiabilidad y
  menor costo operativo; el resto del stack (mapas, geocoder, ruteo, SMS, email, Telegram) sigue siendo
  open-source self-host.
- **iOS** requiere APNs por debajo de FCM; se configura cuando la app de clientes llegue a iOS.

### Neutras
- La arquitectura de la capa de notificaciones no cambia: push sigue siendo un canal de la cascada del
  ADR 006; solo cambia su transporte a FCM.

## Referencias

- ADR 006 (notificaciones: push, email, SMS): el push de la app es FCM.
- ADR 008 (outbox): envío asíncrono con reintentos e idempotencia.
- ADR 015 (notificaciones open-source, sin WhatsApp de pago): cascada FCM → Telegram → Email → SMS.
- Roadmap: C-NOT-01, I-11, S-11, F0-06.
- Firebase Cloud Messaging — API HTTP v1, publicación por topic, SDK `firebase_messaging` (Flutter).
