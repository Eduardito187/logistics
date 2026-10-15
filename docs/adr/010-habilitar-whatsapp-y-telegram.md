# ADR 010 — Habilitar WhatsApp y Telegram como canales de notificación

**Status:** Superado en parte por el **ADR 015**. La regla open-source del ADR 013 (cero servicios de pago) **deshabilita WhatsApp Business API** (es de pago). **Telegram se conserva** (gratis) y se promueve a canal de cliente; el SMS pasa a **gateway open-source**. El resto de esta ADR (cascada + reglas versionadas + outbox) sigue vigente.
**Fecha:** 2026-10-13
**Deciders:** Eduard Huallata

## Contexto

El ADR 006 decidió una cascada de canales **sin WhatsApp** por su costo por mensaje (~0.025 USD en
Bolivia, estimado ~1.100 USD/mes), apoyándose en push de la app de clientes, web push y email, con SMS
solo como respaldo crítico. Tras revisar ese ADR, el dueño del producto decide **habilitar WhatsApp**
(muchos clientes no tienen la app instalada ni activan web push, y WhatsApp tiene la mayor tasa de
lectura del país) y, de paso, **Telegram** (prácticamente gratis, útil para operadores internos y
clientes que lo prefieran). Esto no descarta la lógica de costo del ADR 006: la busca acotar.

## Decisión

Se agregan **WhatsApp** y **Telegram** como canales adicionales dentro de la misma capa de
notificaciones del ADR 006 (cascada de canales + reglas configurables por tipo de mensaje):

- **WhatsApp** (WhatsApp Business API, vía Meta o un proveedor tipo Twilio) deja de estar prohibido.
  Para contener el costo se mantiene gobernado por las reglas por tipo de mensaje y, como SMS, con un
  **tope configurable**: se reserva para los mensajes de alto valor (confirmación de ventana/slot,
  "en camino", entrega) y no para cada evento. Los canales gratuitos (push, web push, email) siguen
  siendo el camino preferente de la cascada; WhatsApp actúa cuando esos no alcanzan al cliente o la
  regla del tipo de mensaje lo pide.
- **Telegram** (bot propio) se agrega como canal gratuito adicional, para operadores y para clientes
  que vinculen su chat.
- La **elección de canal por tipo de mensaje es configuración versionada**, no código: habilitar o
  acotar WhatsApp/Telegram es cambiar una regla, no desplegar.
- El envío sigue siendo I/O externo por **outbox** (ADR 008): nada de HTTP síncrono en el request.

## Alternativas consideradas

### Mantener el ADR 006 tal cual (sin WhatsApp)
- Pros: costo de notificación cercano a cero.
- Contras: deja fuera al segmento sin app ni web push, justo donde WhatsApp tiene mejor alcance y
  lectura. El dueño lo considera una pérdida de calidad de servicio mayor que el costo acotado.

### WhatsApp como canal principal para todo
- Pros: máxima tasa de lectura en todos los mensajes.
- Contras: el costo se dispara (el escenario que el ADR 006 quería evitar). Por eso se usa acotado por
  regla y tope, no como canal por defecto.

## Consecuencias

### Positivas
- Mejor alcance y tasa de lectura en el segmento sin app; WhatsApp y Telegram quedan disponibles por
  configuración.
- La decisión de costo del ADR 006 no se tira: se traduce en topes y reglas por tipo de mensaje.

### Negativas / trade-offs aceptados
- Reaparece un costo variable por WhatsApp; se controla con tope y reglas, y se vigila en la revisión
  periódica de costos.
- Dos integraciones más que operar y monitorear (plantillas aprobadas de WhatsApp, bot de Telegram,
  deliverability, dead-letter).

### Neutras
- La arquitectura de la capa de notificaciones no cambia: solo se suman dos canales a la cascada ya
  existente del ADR 006.

## Referencias

- Revisa el ADR 006 (habilita WhatsApp, antes excluido por costo; agrega Telegram).
- ADR 008 (outbox): envío asíncrono con reintentos e idempotencia.
- Roadmap: ítems D-01, C-NOT-01, C-NOT-04, C-SET-01, F0-08, CONT-06.
