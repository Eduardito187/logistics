---
name: roadmap-b2b-b2c-c2c
description: Roadmap para operar e-commerce B2B+B2C+C2C (mayorista+minorista); huecos y fases sobre los 25 contextos
metadata:
  node_type: memory
  type: project
  originSessionId: 59988cd8-42b3-4864-9323-6ddaa2471766
  modified: 2026-10-27T18:14:16.428Z
---

Reunión con POS/MPS (2026-10): pidieron cubrir mayorista/retail/almacén/e-commerce, planificación,
control de flota, tracking, entregas (camiones/trailers/transportadoras/terceros), y sistemas Lisa+SAP.
Roadmap completo en `docs/roadmap-ecommerce-b2b-b2c-c2c.md`.

Hallazgo clave: casi todo lo que listaron YA existe (25 contextos; B2C retail completo, marketplace/C2C
con fundaciones de las Fases 0–5 de [[marketplace-roadmap]]). Los huecos reales para B2B+B2C+C2C:

- **Fase S (fundación):** segmentación — VO `SalesChannel` + `CustomerType` en shared, campos aditivos
  en `shipments` (default B2C/consumer, retail intacto). Desbloquea el resto.
- **Fase M:** logística mayorista B2B — unidad de manejo (pallet/caja), citas de descarga (capacity eje
  delivery_appointment), POD B2B formal (parcial/rechazo), entregas programadas, consolidación de carga.
- **Fase W:** WMS profundo — ubicaciones/bins, putaway, picking (ola/lote/zona), packing, slotting,
  conteo cíclico (extiende inventory/inbound).
- **Fase V:** taxonomía de vehículos (rígido/**trailer**/van/moto/tercero) + capacidad; ruteo respeta
  tipo/capacidad (R-04 pendiente). Habilita el "trailers" de POS/MPS.
- **Fase T:** transportadoras/3PL profundo (tendering, rate-shopping, guía por carrier, webhooks).
- **Fase I:** capa de canales/conectores ACL+outbox (e-commerce/Magento, POS, marketplace, SAP, Lisa, EDI).
- **Fase C:** C2C puro (pickup al vendedor, devoluciones C2C, KYC/payout).
- **Fase P:** POS/tienda como fulfillment (ship-from-store, BOPIS con stock, devolución en tienda).
- **Fase D:** demanda/capacidad (MPS): pronóstico, load planning, S&OP/simulador.

**Lisa = LISA WMS** (confirmado 2026-10-27): WMS certificado para SAP Business One (add-on de N'ware),
orientado a mayoristas/distribución. WMS completo (ubicaciones/LP/putaway dirigido/picking lote/cross-
dock/FIFO-FEFO/conteo/RF/multi-almacén/3PL/EDI/carriers). **Reencuadra la Fase W: integrar LISA, NO
reconstruir el WMS.** Frontera (§2.5 del doc): stock propio+almacén = LISA/SAP B1; marketplace y nodos
fuera de LISA = nuestra inventory; transporte/ruteo/última milla/GPS/tracking/flota/carriers = nuestra
plataforma. Handoff: LISA deja "orden lista para despacho" → nosotros planificamos/ejecutamos/visibilizamos
→ devolvemos estado de entrega a SAP B1. **El sitio de LISA NO documenta API pública** → confirmar método
con N'ware (DI-API / Service Layer SAP B1 / EDI / vía SAP B1 como hub) = ÚNICO bloqueo externo real (Fase
W-A/I-04).

Decisiones del dueño (2026-10-27): **todo a alcance COMPLETO** — C2C real con KYC/payout/pickup; WMS =
integración LISA + WMS propio solo en nodos sin LISA; B2B modela flete/incoterms (dato logístico); EDI sí;
prioridad = todo, secuencia por olas (S fundación primero). Nada de recortar alcance.

Principio: aditivo (no romper B2C), reutilizar aguas abajo, outbox para I/O externo, contextos
autónomos. Relacionado: [[roadmap-build-plan]], [[marketplace-roadmap]], [[project-architecture]].
