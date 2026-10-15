// Mapas de estado del módulo Entregas: traducen los valores de dominio (execution) a etiqueta en
// español + variant de StatusBadge. Centralizados aquí para reusarlos en Index y Show sin duplicar.

// Estado de la entrega (DeliveryStatus del contexto execution).
const deliveryStatusMap = {
    PENDING: { label: 'Pendiente', variant: 'gray' },
    PREPARED: { label: 'Preparada', variant: 'blue' },
    LOADED: { label: 'Cargada', variant: 'blue' },
    IN_TRANSIT: { label: 'En tránsito', variant: 'amber' },
    DELIVERED: { label: 'Entregada', variant: 'green' },
    FAILED: { label: 'Fallida', variant: 'red' },
};

// Tipo de escaneo de terreno (ScanType del contexto execution).
const scanTypeMap = {
    PREPARATION: { label: 'Preparación', variant: 'gray' },
    LOAD: { label: 'Carga', variant: 'blue' },
    UNLOAD: { label: 'Descarga', variant: 'blue' },
    NODE_RECEPTION: { label: 'Recepción en nodo', variant: 'amber' },
    IN_TRANSIT: { label: 'En tránsito', variant: 'amber' },
    PROOF_OF_DELIVERY: { label: 'Prueba de entrega', variant: 'green' },
};

// Motivo de fallo tipificado (FailureReason del contexto execution).
const failureReasonMap = {
    CUSTOMER_ABSENT: 'Cliente ausente',
    WRONG_ADDRESS: 'Dirección incorrecta',
    REJECTED: 'Rechazado',
    DAMAGED: 'Dañado',
    OTHER: 'Otro',
};

export const deliveryStatus = (s) => deliveryStatusMap[s] ?? { label: s ?? '—', variant: 'gray' };
export const scanType = (s) => scanTypeMap[s] ?? { label: s ?? '—', variant: 'gray' };
export const failureReason = (s) => failureReasonMap[s] ?? s ?? '—';
