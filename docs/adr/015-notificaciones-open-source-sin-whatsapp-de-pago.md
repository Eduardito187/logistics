# ADR 015 — Notificaciones 100% open-source: sin WhatsApp de pago, Telegram y SMS por gateway libre

**Status:** Accepted (revisa el ADR 010; supera su habilitación de WhatsApp de pago. Actualiza el ADR 006)
**Fecha:** 2026-10-13
**Autores:** @eduard
**Deciders:** Eduard Huallata

## Contexto

El ADR 010 había **habilitado WhatsApp** (WhatsApp Business API vía Meta o Twilio) y Telegram como
canales de la cascada de notificaciones, aceptando un costo variable acotado por regla y tope. La
regla transversal del dueño (ver ADR 013) cambia la restricción de fondo: **cero servicios de pago**.
La WhatsApp Business API **cuesta por mensaje** y pasa por Meta o un proveedor de pago, así que queda
**fuera**. Hay que redefinir los canales de la cascada para que todos sean gratuitos y, en lo posible,
auto-hospedables, sin degradar demasiado el alcance al cliente.

Esta ADR solo trata la **selección de canales** (WhatsApp/Telegram/SMS/email). El **transporte de
push** (Firebase Cloud Messaging) lo decide el ADR 014.

## Decisión

Se redefine la cascada de canales del ADR 006/010 para que sea **100% sin pago**:

- **WhatsApp Business API queda deshabilitado** como canal de producción por ser de pago. La capa de
  notificaciones mantiene la abstracción de canal, pero WhatsApp no se activa por regla (el adaptador,
  si existe, queda inerte/null). Esto **supera** la decisión del ADR 010 de usarlo acotado.
- **Telegram se mantiene y se promueve a canal de cliente**, no solo interno. Es gratuito (bot propio
  vía Bot API), con buena tasa de lectura, y cubre al segmento sin app que el ADR 010 quería alcanzar
  con WhatsApp. El cliente que vincule su chat recibe por Telegram; operadores internos también.
- **SMS por gateway open-source**, no por proveedor de pago. El SMS de respaldo crítico (confirmación
  de ubicación con baja confianza, "no abrió push ni email") sale por un **gateway SMS libre**
  —**Kannel**, **Jasmin** o un **gateway Android** (p.ej. SMS Gateway/`android-sms-gateway`) con un
  chip/modem propio— detrás del contrato de canal (`SMS_DRIVER`, `SMS_GATEWAY_URL`). Sigue siendo el
  último recurso, con **tope configurable** (el costo del SMS es del operador móvil, no una suscripción
  SaaS).
- **Email transaccional** sigue como canal casi gratuito (sendmail nativo del host; sin SMTP propio).
- **Push** de la app por Firebase Cloud Messaging (FCM), topic por envío (ADR 014). Es el primer canal
  de la cascada: **FCM → Telegram → Email → SMS**.

La **elección de canal por tipo de mensaje sigue siendo configuración versionada**, no código:
habilitar/acotar un canal es cambiar una regla, no desplegar. El envío sigue por **outbox** (ADR 008).

## Alternativas consideradas

### Mantener WhatsApp acotado (ADR 010)
- Pros: la mayor tasa de lectura del país; alcanza al cliente sin app.
- Contras: es de pago (~0.025 USD/mensaje) y pasa por Meta/Twilio; **lo prohíbe la regla del dueño**.

### Prescindir también de SMS (solo canales 100% gratis y propios)
- Pros: cero costo variable absoluto.
- Contras: el SMS es el único respaldo que llega a un teléfono sin datos ni apps; se conserva como
  último recurso con tope, y el costo es del operador móvil, no una suscripción.

### WhatsApp vía librerías no oficiales (web/multi-device)
- Pros: "gratis".
- Contras: viola los términos de WhatsApp, frágil y con riesgo de bloqueo de números; descartado.

## Consecuencias

### Positivas
- Notificaciones sin ninguna suscripción de pago; Telegram cubre buena parte del alcance que motivaba
  WhatsApp, y es gratuito y auto-operable (bot propio).
- Coherencia con la regla open-source de todo el proyecto (ADR 013); el push de la app es la excepción
  explícita (FCM/Firebase, ADR 014).

### Negativas / trade-offs aceptados
- Se pierde el alcance de WhatsApp en el segmento que no usa app ni Telegram; se compensa con
  Telegram + email + SMS de respaldo, y se vigila el % alcanzado en el piloto.
- Operar un gateway SMS libre (Kannel/Jasmin/Android gateway) y un bot de Telegram es infraestructura y
  operación propias (números, plantillas, deliverability, dead-letter).
- El SMS conserva un costo variable del **operador móvil** (no SaaS); se mantiene el tope del ADR 010.

### Neutras
- La arquitectura de la capa de notificaciones (cascada + reglas versionadas por tipo + outbox) no
  cambia: solo cambia **qué canales** están habilitados y su transporte.

## Referencias

- Revisa/supera el ADR 010 (deshabilita WhatsApp de pago; conserva Telegram; SMS por gateway libre).
- Actualiza el ADR 006 (la cascada ahora es 100% sin pago).
- ADR 013 (todo open-source, sin Google ni pago) y ADR 014 (push sin Firebase).
- ADR 008 (outbox): envío asíncrono con reintentos e idempotencia.
- Roadmap: C-NOT-01, C-NOT-04, C-SET-01, F0-08, CONT-06.
- Telegram Bot API, Kannel, Jasmin, gateways SMS Android open-source.
