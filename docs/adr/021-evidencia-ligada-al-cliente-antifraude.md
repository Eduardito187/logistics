# ADR 021 — Evidencia de entrega ligada al cliente (anti-fraude), validación on-premise

Fecha: 2026-11-05
Estado: aceptado
Contexto bounded: `logistics/execution`

## Contexto

El dueño pide que las **firmas de entrega** y las **fotos del documento del cliente** se guarden
**ligadas al cliente** (no solo al envío) y que el backend **valide** la evidencia contra el cliente
correcto, para evitar fraude (intercambiar una evidencia por otra, entregar a quien no es). Debe ser
**on-premise** (ninguna PII del cliente a nubes de terceros), aditivo y offline-first.

## Decisión

### 1. La evidencia es un hecho de dominio, no un adjunto

Se añade el agregado `DeliveryEvidence` (append + auditoría) con:

- **Vínculo al cliente**: snapshot inmutable de la identidad (`customer_id`, `document_id`, nombre,
  teléfono) tal como estaba en la entrega. Es un snapshot a propósito: la evidencia es histórica y debe
  conservar contra quién se validó.
- **Integridad**: `sha256` del archivo. Si el archivo cambia, el hash no cuadra → no se puede intercambiar
  una evidencia por otra.
- **Auditoría inmutable**: quién capturó (usuario+dispositivo), cuándo (`captured_at`) y el GPS. Solo
  evoluciona el **estado de validación**; la captura nunca se muta.
- **Tipos** (`EvidenceKind`): se agregan `signature` y `id_document` a los `pod`/`damage` existentes, cada
  uno en su disco privado (PII).

La captura emite `DeliveryEvidenceCaptured` al **outbox**: la validación corre **asíncrona** (nada de I/O
ni ML síncrono en el request, ADR 002).

### 2. La validación es SEÑAL, nunca bloqueo

El estado es `pending | ok | review | mismatch`. `review` y `mismatch` escalan a **revisión humana**; **jamás
frenan la entrega automáticamente**. Un falso rechazo que frene una entrega legítima es peor que dejar pasar
un caso dudoso a auditoría. Se consulta por envío (`GET /app/deliveries/{shipmentId}/evidence`) y el
resultado lo escribe un validador por `POST /integrations/evidence/{evidenceId}/validation`.

### 3. Cruce de documento: regla pura on-premise, sin ML

`DocumentNumberMatcher` (dominio puro) cruza el número leído por OCR contra el del cliente: normaliza a solo
dígitos, `ok` si coincide, `review` si difiere en un dígito por confusión típica de OCR (0↔8, 1↔7, …) o si
falta lectura, `mismatch` si difiere en longitud o en un dígito no confundible. El OCR en sí vive en un
**sidecar**, no en el backend.

### 4. Stack open-source (investigación on-premise) — qué se adopta y qué NO

Se investigaron herramientas OSS (fan-out). Decisión por pieza:

- **OCR de documento (cédula BO)**: **PaddleOCR** (Apache-2.0, mejor en foto de celular) + **OpenCV**
  (recorte/dewarp/deglare — sube más la precisión que cambiar de motor) + **RapidFuzz**, como sidecar
  Python asíncrono. MRZ (`fast-mrz`/PassportEye) solo como confirmación oportunista: la cédula vieja NO
  tiene MRZ, así que el número impreso es el campo universal. Fiabilidad **media** (conviven cédula vieja
  sin MRZ y nueva de policarbonato): por eso es señal, no bloqueo.
- **Firma manuscrita**: baseline obligatorio = **capturar + hash + trazos crudos + auditoría** (100%
  confiable on-prem). Señal ML opcional (fase 2) con **`luizgh/sigver` (SigNet, BSD-3)** como *triage*,
  nunca veredicto. **Caveat de licencia**: los pesos preentrenados de SigNet vienen de GPDS
  (**non-commercial**) → para uso comercial hay que reentrenar con dataset propio/CEDAR. La app debería
  **persistir los trazos crudos** desde ya (habilita verificación ONLINE, mejor para firma en pantalla).
- **Face match / liveness**: **FUERA del v1**. Extensión futura opt-in detrás de un flag desactivado por
  defecto. Razones: (a) nice-to-have, no núcleo; (b) las libs de mayor precisión tienen **licencia no
  comercial** en los pesos (InsightFace/ArcFace, VGG-Face) — comercialmente seguros serían
  facenet-pytorch (MIT) o DeepFace con Facenet512/SFace + Silent-Face-Anti-Spoofing (Apache-2.0); (c)
  biometría facial de clientes es PII sensible y Bolivia no tiene aún ley de datos plenamente vigente →
  estándar prudente = consentimiento informado, minimización, no persistir rostros.

### 5. On-premise y privacidad

Todo el procesamiento (OCR/firma) corre en un sidecar en la red interna; **ninguna PII sale a nubes de
terceros** (consistente con la regla CERO-CDN). Los discos de evidencia (`signature`, `id_document`) son
**privados**. El evento del outbox lleva solo referencias (ids, hash), no el archivo ni PII pesada.

### 6. Licenciamiento OSS (requisito FIRME del dueño)

Requisito firme del dueño (relayed por la sesión de la app, 2026-11-09): **todo el stack del pipeline
anti-fraude debe ser open-source y sin costo de licencias — incluidos los PESOS de los modelos de ML.**

Criterio de aceptación: cada dependencia / paquete / modelo del pipeline debe tener licencia permisiva
(MIT / Apache-2.0 / BSD) **y** pesos redistribuibles para uso comercial, sin cuotas. Lo que no cumpla se
reemplaza o se deja fuera; el baseline v1 (hash + auditoría + captura + cruce por OCR) cubre v1 **sin ML
de pesos restrictivos**.

Implicancias concretas sobre la investigación previa:

- **OCR de documento**: PaddleOCR + OpenCV + RapidFuzz → OK (Apache-2.0 / BSD), se mantiene.
- **Verificación de firma**: los pesos de **SigNet** (entrenados en GPDS) son **non-commercial → NO usar
  tal cual**. Usar enfoque clásico OpenCV / features con licencia permisiva, o entrenar sobre datos
  abiertos/propios. El baseline hash + auditoría + captura (sin ML) ya es 100% OSS y cumple para v1.
- **Face match / liveness**: los pesos de mayor precisión (InsightFace / ArcFace, VGG-Face) son
  **non-commercial → FUERA** salvo un modelo con licencia comercial abierta y pesos redistribuibles
  (p. ej. facenet-pytorch MIT, o Facenet512 / SFace + Silent-Face-Anti-Spoofing Apache-2.0). Sigue siendo
  nice-to-have, no núcleo (ver §4).

## Qué entrega esta tanda (backend) y qué queda para el sidecar

- **Hecho ahora** (DoD verde): agregado + hash + auditoría + snapshot de cliente, tipos `signature`/
  `id_document`, endpoints de captura/consulta/write-back, `DocumentNumberMatcher`, outbox, openapi aditivo.
- **Pendiente (sidecar Python, fuera de este PHP)**: el OCR real (PaddleOCR) y la señal de firma, que
  consumen `DeliveryEvidenceCaptured` y llaman al write-back. El seam ya está: el backend no cambia cuando
  se enchufe.

## Consecuencias

- La app sube firma/documento ligados al cliente y muestra el estado de validación; el fraude por
  intercambio de evidencia queda cubierto por el hash + auditoría aunque el OCR aún no esté enchufado.
- Ninguna decisión automática frena una entrega: el riesgo operativo de falsos rechazos queda acotado.
