# 06 — Checklist de accesos y decisiones técnicas (F0-FIRE)

> Fase 0 (Discovery). Cubre **F0-FIRE-01** (acceso Firebase), **F0-FIRE-02** (% de clientes con app
> y notificaciones activas), **F0-FIRE-03** (impresoras Zebra y celulares) y **F0-FIRE-04**
> (proveedor y costo de SMS de respaldo). Son los accesos y decisiones que **habilitan** la capa de
> notificaciones de ADR 006 y el etiquetado QR. Sin esto, la Fase 1 no puede notificar ni imprimir.

Estado por ítem: `[ ]` pendiente · `[~]` en progreso · `[x]` hecho y verificado.
Completar **responsable** y **estado** en cada fila; dejar la evidencia (link, captura, documento) en
la columna de notas.

## F0-FIRE-01 — Acceso al proyecto Firebase de la app de clientes

Necesario para publicar en los **topics FCM por envío** (`shipment_<id>`) sin manejar tokens de los
clientes (ADR 006). La plataforma publica en topics; **no** guarda tokens.

| # | Ítem | Responsable | Estado | Notas / evidencia |
| --- | --- | --- | --- | --- |
| 1.1 | Identificar el proyecto Firebase real de la app de clientes (project id) | | `[ ]` | |
| 1.2 | Conseguir rol/credencial para **publicar en topics** (service account con permisos de FCM) | | `[ ]` | Mínimo privilegio: solo envío de mensajes, no admin del proyecto |
| 1.3 | Generar la **credencial de servidor** (JSON de service account) y guardarla fuera del repo | | `[ ]` | Va a gestión de secretos (ver `P1-INFRA-04`) |
| 1.4 | Confirmar que la app **se suscribe al topic** `shipment_<id>` al abrir su pedido | | `[ ]` | Dependencia del equipo de la app de clientes |
| 1.5 | Confirmar soporte de **web push** (FCM web) para el token de la página de tracking | | `[ ]` | iPhone requiere "agregar a inicio"; respaldo es email (ADR 006) |
| 1.6 | Prueba de humo: publicar un mensaje a un topic de prueba y recibirlo en un device | | `[ ]` | |

## F0-FIRE-02 — % de clientes con app y notificaciones activas (riesgo ADR 006)

ADR 006 apuesta a push gratis; su punto débil es **cuántas entregas realmente tienen un cliente con
la app y notificaciones activas**. Medirlo define cuánto peso cae en web push, email y SMS.

| # | Ítem | Responsable | Estado | Notas / evidencia |
| --- | --- | --- | --- | --- |
| 2.1 | Obtener base de ~23k clientes de la app y su estado de notificaciones (opt-in FCM) | | `[ ]` | Dato del equipo de la app de clientes |
| 2.2 | Cruzar clientes con app contra el **universo de entregas** del piloto (no todos compran) | | `[ ]` | La métrica es "% de **entregas**", no "% de clientes" |
| 2.3 | Estimar **% de entregas** cuyo cliente tiene app **con notificaciones activas** | | `[ ]` | Entregable central de F0-FIRE-02 |
| 2.4 | Estimar cobertura del canal **web push** para el resto (quién llega al tracking) | | `[ ]` | |
| 2.5 | Estimar **% que caería a email/SMS** → dimensiona el costo de SMS (F0-FIRE-04) | | `[ ]` | Insumo directo del tope de SMS |
| 2.6 | Documentar el riesgo y el plan si el % es bajo (empujar instalación desde tracking) | | `[ ]` | |

## F0-FIRE-03 — Inventario de impresoras Zebra y celulares

El etiquetado QR (package `labels`) necesita impresoras reales; la app interna corre en los celulares
de la operación. Hay que saber qué hay antes de prometer impresión en ruta.

### Impresoras Zebra

Plantilla de inventario (una fila por impresora). Sugerido llevarlo en una planilla aparte; aquí se
consolida el estado del relevamiento.

| # | Ítem | Responsable | Estado | Notas / evidencia |
| --- | --- | --- | --- | --- |
| 3.1 | Listar todas las impresoras Zebra: **modelo**, nodo donde está, nº de serie | | `[ ]` | p. ej. ZQ511 (móvil), ZD421 (escritorio) |
| 3.2 | Registrar **firmware** de cada una y si admite actualización | | `[ ]` | Relevante para compatibilidad de comandos de impresión |
| 3.3 | Registrar **conectividad**: Bluetooth / WiFi / USB por impresora | | `[ ]` | La app imprime por BT/WiFi; descartar solo-USB para ruta |
| 3.4 | Tamaño de etiqueta / papel disponible por impresora | | `[ ]` | Debe caber el QR + código de nodo legible |
| 3.5 | Estado operativo (funciona / falla / sin uso) y stock de consumibles | | `[ ]` | |

### Celulares de la operación

| # | Ítem | Responsable | Estado | Notas / evidencia |
| --- | --- | --- | --- | --- |
| 3.6 | Inventario de celulares: modelo, SO y versión, propietario (empresa / BYOD) | | `[ ]` | La app interna corre aquí |
| 3.7 | Verificar **versión mínima de SO** soportada por la app Flutter | | `[ ]` | |
| 3.8 | Verificar **cámara** apta para escanear QR y **Bluetooth** para la Zebra | | `[ ]` | |
| 3.9 | Plan de datos / conectividad en ruta (modo offline de la app) | | `[ ]` | La app debe tolerar sin señal (ver P1-API-04 idempotencia) |

## F0-FIRE-04 — Proveedor de SMS de respaldo (costo + tope)

El SMS es el **último escalón** de la cascada (ADR 006): solo respaldo crítico de ubicación con baja
confianza cuando el cliente no abrió push ni email, y **con tope**. Hay que elegir proveedor, costo y
tope mensual.

| # | Ítem | Responsable | Estado | Notas / evidencia |
| --- | --- | --- | --- | --- |
| 4.1 | Relevar proveedores de SMS en Bolivia (local y/o global tipo gateway) | | `[ ]` | Comparar cobertura de las 3 operadoras |
| 4.2 | Obtener **costo por SMS** y condiciones (volumen, setup, sender id) | | `[ ]` | Comparar contra 0.025 USD/msg de WhatsApp (contexto ADR 006) |
| 4.3 | Estimar **volumen esperado** a partir de F0-FIRE-02 (% que cae a SMS) | | `[ ]` | Volumen × costo = gasto mensual estimado |
| 4.4 | Definir **tope mensual configurable** (nº de SMS y/o monto) | | `[ ]` | Va como regla en `settings` (versionada) |
| 4.5 | Verificar API/credenciales del proveedor y que el envío encaje en **outbox** | | `[ ]` | Nunca HTTP síncrono en el request (CLAUDE.md) |
| 4.6 | Definir política de a qué mensajes se les permite escalar a SMS | | `[ ]` | Solo confirmación crítica de ubicación, no encuestas ni avisos de rutina |

## Resumen de entregables de F0-FIRE

- Credencial de servidor Firebase (fuera del repo) y prueba de publicación a un topic.
- Número de **% de entregas con push activo** y el plan de mitigación si es bajo.
- Inventario de impresoras Zebra y celulares, con los equipos no aptos marcados.
- Proveedor de SMS elegido, con costo unitario, volumen estimado y **tope mensual** acordado.
