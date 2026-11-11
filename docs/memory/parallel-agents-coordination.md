---
name: parallel-agents-coordination
description: "El dueño pide agentes en paralelo; patrón que funcionó el 2026-11-11 (territorios disjuntos, Pest serializado por el coordinador, sin tocar la base de dev)"
metadata:
  node_type: memory
  type: feedback
  originSessionId: 5ae2cd97-4f6d-4a3c-8c55-472d9c313fac
  modified: 2026-11-11T14:25:14.908Z
---

El dueño pide explícitamente "que tus agentes trabajen esto en paralelo" / "ayudate de los agentes".
Patrón que funcionó (4 agentes simultáneos: núcleo de backup, contenedor+migración, odómetro, polling JS):

- **Territorio de archivos explícito y disjunto** en cada brief, listando también lo que tocan los OTROS
  (incluidos archivos que el coordinador editó ese día, para que los lean y conserven).
- **Pest: un solo dueño a la vez.** Solo el agente que lo necesita corre SUS tests; el resto tiene Pest
  prohibido (escriben tests sin correrlos) y el coordinador corre la suite completa al final, una vez.
- **Base de dev intocable:** verificar con un Postgres descartable propio (`docker run postgis/postgis:17-3.5-alpine`)
  o leyendo dev en solo lectura; escrituras de negocio mínimas y reportadas (p.ej. una lectura de odómetro de prueba).
- **Contrato con nombres exactos** cuando un agente depende de otro (comandos `backup:*`).
- **Cambios de alcance a mitad de camino** se mandan por SendMessage a cada agente afectado; cuestan
  retrabajo (el de backups tardó ~26 min por dos cambios). Mejor fijar alcance antes cuando es ambiguo
  ("backup completo" para el dueño = SOLO la base, ver [[backup-manual-only]]).
- El dueño ve las tareas en background en la UI y pregunta si algo tarda: revisar actividad real
  (mtime de archivos, contenedores) antes de responder; nunca dejar una tarea colgada sin tope.
- El dueño hace sus propios commits (`git add .; git commit; git push`): no commitear por él.

**Why:** pedido repetido del dueño; evita pisarse archivos y corromper `logistics_test`.
**How to apply:** ver también [[multisession-shared-tree]] (varias sesiones en el mismo árbol).
