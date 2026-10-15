<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Personnel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistory;
use Logistics\Fleet\Application\Query\ListDrivers\ListDrivers;
use Logistics\Fleet\Application\Query\ListHelpers\ListHelpers;
use Logistics\Fleet\Application\ReadModel\DriverHistoryView;
use Logistics\Fleet\Application\ReadModel\DriverView;
use Logistics\Fleet\Application\ReadModel\HelperView;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentType;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\FleetDocumentModel;
use Logistics\Shared\Application\Query\QueryBus;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * App web (Inertia) — módulo Personal. Lee el read-side de fleet (QueryBus → ListDrivers/ListHelpers,
 * DriverView/HelperView) para listar y mostrar los perfiles de choferes y ayudantes con sus datos
 * personales ya existentes, sus documentos (fleet_documents, dueño polimórfico por valor) y, para el
 * chofer, su desempeño operativo (GetDriverHistory → DriverHistoryView). Solo lectura.
 */
final class PersonnelController extends Controller
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Índice: choferes (placa de licencia, categoría, edad, estado) y ayudantes (nombre, teléfono, estado).
     */
    public function index(): Response
    {
        /** @var list<DriverView> $drivers */
        $drivers = $this->queryBus->ask(new ListDrivers);
        /** @var list<HelperView> $helpers */
        $helpers = $this->queryBus->ask(new ListHelpers);

        $driverRows = array_map(static function (DriverView $driver): array {
            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'license_number' => $driver->licenseNumber,
                'license_category' => $driver->licenseCategory ?? '—',
                'age' => $driver->age,
                'active' => $driver->active,
            ];
        }, $drivers);

        $helperRows = array_map(static function (HelperView $helper): array {
            return [
                'id' => $helper->id,
                'name' => $helper->name,
                'phone' => $helper->phone ?? '—',
                'active' => $helper->active,
            ];
        }, $helpers);

        return Inertia::render('Personnel/Index', [
            'drivers' => $driverRows,
            'helpers' => $helperRows,
        ]);
    }

    /**
     * Perfil completo del chofer: datos personales, documentos y desempeño operativo en un rango.
     */
    public function driverShow(Request $request, string $driver): Response
    {
        $view = $this->findDriver($driver);

        [$from, $to] = $this->resolveRange($request);

        /** @var DriverHistoryView $history */
        $history = $this->queryBus->ask(new GetDriverHistory($view->id, $from, $to));

        return Inertia::render('Personnel/DriverShow', [
            'driver' => array_merge($view->toArray(), [
                'photo_url' => $this->fileUrl($view->photoPath),
            ]),
            'documents' => $this->documentsOf(DocumentOwnerType::Driver, $view->id),
            'performance' => $history->toArray(),
            'reasonLabels' => $this->reasonLabels(),
        ]);
    }

    /**
     * Perfil del ayudante: datos personales y documentos.
     */
    public function helperShow(string $helper): Response
    {
        $view = $this->findHelper($helper);

        return Inertia::render('Personnel/HelperShow', [
            'helper' => array_merge($view->toArray(), [
                'photo_url' => $this->fileUrl($view->photoPath),
            ]),
            'documents' => $this->documentsOf(DocumentOwnerType::Helper, $view->id),
        ]);
    }

    /**
     * Busca un chofer entre las proyecciones de lectura; 404 si no existe.
     */
    private function findDriver(string $id): DriverView
    {
        /** @var list<DriverView> $drivers */
        $drivers = $this->queryBus->ask(new ListDrivers);

        foreach ($drivers as $driver) {
            if ($driver->id === $id) {
                return $driver;
            }
        }

        throw new NotFoundHttpException('Driver not found.');
    }

    /**
     * Busca un ayudante entre las proyecciones de lectura; 404 si no existe.
     */
    private function findHelper(string $id): HelperView
    {
        /** @var list<HelperView> $helpers */
        $helpers = $this->queryBus->ask(new ListHelpers);

        foreach ($helpers as $helper) {
            if ($helper->id === $id) {
                return $helper;
            }
        }

        throw new NotFoundHttpException('Helper not found.');
    }

    /**
     * Rango por defecto: del primer día del mes corriente hasta hoy. Acepta from/to ('Y-m-d') por query.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveRange(Request $request): array
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $from = is_string($from) && $this->isValidDate($from) ? $from : date('Y-m-01');
        $to = is_string($to) && $this->isValidDate($to) ? $to : date('Y-m-d');

        return [$from, $to];
    }

    /**
     * Valida que una cadena tenga el formato 'Y-m-d' y sea una fecha real.
     */
    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Documentos del dueño (chofer o ayudante), en forma primitiva: tipo legible, fechas y URL del archivo.
     *
     * @return list<array{document_type: string, expires_on: string, issued_on: string|null, note: string|null, file_url: string|null}>
     */
    private function documentsOf(DocumentOwnerType $ownerType, string $ownerId): array
    {
        $documents = FleetDocumentModel::query()
            ->where('owner_type', $ownerType->value)
            ->where('owner_id', $ownerId)
            ->orderBy('expires_on')
            ->get()
            ->map(fn (FleetDocumentModel $document): array => [
                'document_type' => $this->typeLabel($document->document_type),
                'expires_on' => $document->expires_on,
                'issued_on' => $document->issued_on,
                'note' => $document->note,
                'file_url' => $this->fileUrl($document->file_path),
            ])
            ->all();

        return array_values($documents);
    }

    /**
     * Etiqueta legible del tipo de documento; si no está en el enum, muestra el valor crudo (tipo libre).
     */
    private function typeLabel(string $type): string
    {
        $known = FleetDocumentType::tryFrom($type);

        return $known !== null ? $known->label() : $type;
    }

    /**
     * URL temporal firmada para un archivo privado (foto o documento). Si el disco no soporta URLs
     * temporales (p. ej. 'local'), degrada a null sin romper. Null también si no hay archivo.
     */
    private function fileUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = config('fleet.documents_disk');
        $disk = is_string($disk) && $disk !== '' ? $disk : 'local';

        try {
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes(10));
        } catch (Throwable) {
            // El disco no soporta URLs temporales (local u otro): se degrada a null sin romper la vista.
            return null;
        }
    }

    /**
     * Etiquetas legibles (español) de cada motivo de fallo, para el desglose de desempeño.
     *
     * @return array<string, string>
     */
    private function reasonLabels(): array
    {
        return [
            FailureReason::CustomerAbsent->value => 'Cliente ausente',
            FailureReason::WrongAddress->value => 'Dirección incorrecta',
            FailureReason::Rejected->value => 'Rechazado',
            FailureReason::Damaged->value => 'Dañado',
            FailureReason::Other->value => 'Otro',
        ];
    }
}
