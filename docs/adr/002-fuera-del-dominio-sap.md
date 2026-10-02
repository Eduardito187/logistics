# ADR 002 — La plataforma vive fuera del dominio SAP

**Status:** Accepted
**Fecha:** 2026-10-02
**Deciders:** Eduard Huallata

## Contexto

El sistema de despacho actual solo acepta productos que existen en SAP y aplica todas sus validaciones.
Eso lo vuelve rígido y deja fuera pedidos de otras fuentes. Además, depender de SAP en línea haría que
una caída de SAP detenga la operación de despacho.

## Decisión

- El modelo de la plataforma es propio: envíos, unidades físicas (bultos), nodos y tramos. No conoce
  materiales SAP.
- SAP es un sistema externo más. Solo el conector (`logistics/sap-connector`, Fase 2) sabe hablar con
  SAP: capa anti-corrupción que traduce bulto ↔ material, centro y almacén.
- No se valida stock. La plataforma solo recibe pedidos para entregar.
- Los traspasos de stock en SAP (al recibir en un nodo) son asíncronos: evento → outbox → conector,
  con reintentos, idempotencia por `event_id` y panel de reconciliación.
- El escaneo nunca espera a SAP. Si SAP cae, la operación sigue.

## Consecuencias

### Positivas
- Cualquier fuente de pedidos entra (Magento, tiendas, Excel, dis-commerce después).
- La disponibilidad del despacho no depende de SAP.

### Negativas / trade-offs aceptados
- Puede haber traspasos pendientes por un tiempo: se gestionan desde el panel de reconciliación.
