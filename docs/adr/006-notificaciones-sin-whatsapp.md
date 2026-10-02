# ADR 006 — Notificaciones sin WhatsApp

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

Desde octubre de 2026 la WhatsApp Business API cuesta 0.025 USD por mensaje en Bolivia. Con ~300
entregas diarias y ~5 mensajes por pedido serían ~45.000 mensajes al mes (~1.100 USD). La app de
clientes Dismac tiene ~23k clientes (lanzada hace ~6 meses) y Firebase ya está en uso.

## Decisión

Capa de notificaciones con cascada de canales y reglas configurables por tipo de mensaje:

1. Push de la app de clientes (FCM, gratis), mediante **topics por envío** (`shipment_<id>`): la app
   se suscribe al ver su pedido y la plataforma publica sin conocer tokens.
2. Web push en la página de tracking (FCM, gratis): el cliente sin app activa avisos y el servidor
   suscribe su token al mismo topic.
3. Email transaccional (casi gratis).
4. SMS solo como respaldo crítico: confirmación de ubicación con baja confianza cuando el cliente no
   abrió push ni email. Con tope configurable.

El tracking sirve además como motivo para instalar la app de clientes.

## Consecuencias

### Positivas
- Costo de notificación cercano a cero.
- La plataforma no guarda tokens de la app de clientes.

### Negativas / trade-offs aceptados
- Web push en iPhone requiere agregar la página a la pantalla de inicio; el respaldo es el email.
- Requiere acceso al proyecto Firebase de la app de clientes para publicar en sus topics.
- Pendiente medir el % de entregas cuyo cliente tiene la app con notificaciones activas.
