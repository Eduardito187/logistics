---
name: project-overview
description: "Qué es la plataforma logística de Dismac, su escala, fases y repos"
metadata:
  node_type: memory
  type: project
  originSessionId: 6c3e1293-8377-4d93-8fed-2b1879d36abb
  modified: 2026-10-13T06:09:52.132Z
---

Plataforma logística propia de Dismac que planifica, ejecuta y hace visible cada entrega, **fuera de SAP**. Cubre la red completa: CD→cliente, CD→CD, CD→tienda, tienda→tienda, tienda→CD y recojo en tienda.

- Escala objetivo: ~100 camiones a nivel nacional, ~300 entregas diarias.
- Dos repos unidos por contrato OpenAPI (ADR 007): `logistics` (backend + admin, este repo) y `logistics-app` (app interna Flutter, github.com/Eduardito187/logistics-app).
- App interna única con vista por rol: chofer, preparador, tienda, transportista, supervisor.
- No se valida stock (solo se reciben pedidos para entregar). dis-commerce queda fuera del MVP (~6 meses) y luego entra como un adaptador más.
- Fases: 0 Discovery · 1 Operar (MVP, piloto una ciudad, 10–15 camiones, CD→cliente) · 2 Prometer (cupos, promesa, conector SAP, consolidación) · 3 Optimizar.

Detalle vivo en `docs/vision.md`. Ver [[project-architecture]] y [[project-rules]].
