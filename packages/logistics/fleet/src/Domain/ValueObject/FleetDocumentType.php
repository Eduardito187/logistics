<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

/**
 * Clasificación de los documentos de flota conocidos (CI, licencia, carné de salud, antecedentes, foto).
 *
 * La columna `document_type` de `fleet_documents` sigue siendo un string libre: este enum clasifica los
 * tipos conocidos para la UI y los reportes, y `Other` deja la puerta abierta a tipos no previstos.
 */
enum FleetDocumentType: string
{
    case NationalId = 'national_id';

    case DriverLicense = 'driver_license';

    case HealthCard = 'health_card';

    case CriminalRecord = 'criminal_record';

    case Photo = 'photo';

    case Other = 'other';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID',
            self::DriverLicense => 'Driver license',
            self::HealthCard => 'Health card',
            self::CriminalRecord => 'Criminal record',
            self::Photo => 'Photo',
            self::Other => 'Other',
        };
    }
}
