<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Role;
use App\Http\Controllers\Api\V1\App\Concerns\IssuesDeviceTokens;
use App\Models\ApiChannel;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Execution\Application\Command\OpenDelivery\OpenDelivery;
use Logistics\Fleet\Application\Command\RegisterDriver\RegisterDriver;
use Logistics\Fleet\Application\Command\RegisterVehicle\RegisterVehicle;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Siembra el POOL DE CARGA del load test (Ola 5 del roadmap de alta disponibilidad): N choferes con su
 * camión, dispositivo y token ya emitido, la ruta del día con sus paradas, los envíos con seguimiento
 * público, operadores para los listados, cuentas dedicadas al flujo de login y un canal para el rastreo
 * público. Al final exporta `loadtest/data/pool.json` (gitignored) para que k6 lo consuma.
 *
 * Reglas duras que cumple:
 *  - ADITIVO: solo agrega filas; nunca borra ni modifica datos que no sean del pool.
 *  - IDEMPOTENTE: cada pieza se guarda por su clave de negocio (placa, licencia, email, external_id del
 *    envío, parada/entrega por envío). Correrlo dos veces con los mismos parámetros no duplica nada; solo
 *    rota los tokens y el secreto del canal (por eso se reescribe el pool.json).
 *  - IDENTIFICABLE Y LIMPIABLE: cada fila lleva un prefijo propio (ver las constantes) que `loadtest:cleanup`
 *    usa para borrar SOLO lo sembrado acá.
 *  - Recorre los CAMINOS REALES de la aplicación: los comandos de Application por el CommandBus (los
 *    mismos que usan la API y TodayScenarioSeeder) y el binding usuario↔dispositivo↔token del login.
 *  - Se NIEGA a correr en producción y es dry-run por defecto (`--force` para escribir).
 *
 * Los envíos se siembran SIN teléfono ni email de cliente a propósito: así los reactores de notificaciones
 * no tienen destinatario real al que escribirle durante la prueba.
 */
final class LoadTestPrepareCommand extends Command
{
    use IssuesDeviceTokens;

    /** Placa de los camiones del pool: LOADTEST-0001 … (PlateNumber admite 3-8 + sufijo de 1-4). */
    public const string PLATE_PREFIX = 'LOADTEST-';

    /** Licencia de los choferes del pool: LT-DRV-000001 … */
    public const string LICENSE_PREFIX = 'LT-DRV-';

    /** Prefijo y dominio de los emails del pool: lt-driver-0001@loadtest.dismac.test. */
    public const string EMAIL_PREFIX = 'lt-';

    public const string EMAIL_DOMAIN = 'loadtest.dismac.test';

    /** Huella de los dispositivos del pool (incluye los que crea el flujo de login de k6). */
    public const string DEVICE_PREFIX = 'lt-device-';

    /** Canal de la puerta channels dedicado al rastreo público de la prueba. */
    public const string CHANNEL_NAME = 'LT-loadtest';

    /** Prefijo del external_id / order_reference de los envíos: LT-{lote}-{chofer}-{parada}. */
    public const string SHIPMENT_PREFIX = 'LT-';

    /** Forma exacta del external_id para LIKE (cada `_` es UN carácter): LT-______-____-___. */
    public const string SHIPMENT_LIKE = 'LT-______-____-___';

    /** Prefijo del nombre de cliente de los envíos del pool (segunda llave del filtro de limpieza). */
    public const string CUSTOMER_PREFIX = 'LT ';

    /** Ruta por defecto del pool exportado (relativa a la raíz del proyecto). */
    public const string DEFAULT_OUTPUT = 'loadtest/data/pool.json';

    /** Fallas seguidas tras las que se aborta: un error sistemático no debe repetirse miles de veces. */
    private const int MAX_CONSECUTIVE_FAILURES = 3;

    /** Centro de Santa Cruz de la Sierra, por si el nodo base no trae coordenadas utilizables. */
    private const float FALLBACK_LAT = -17.7833;

    private const float FALLBACK_LNG = -63.1821;

    protected $signature = 'loadtest:prepare
        {--drivers=50 : Number of drivers (each with vehicle, device, token and today\'s route)}
        {--stops=8 : Stops (shipments with public tracking) per driver}
        {--operators=50 : Supervisor accounts with token for the listing scenarios}
        {--logins=10 : Accounts reserved for the login flow (no pre-issued token)}
        {--batch= : 6-character batch tag for the shipments (default: today as ymd)}
        {--node= : Code of the base node for the vehicles (default: first distribution center)}
        {--password=password123 : Known password for every pool account}
        {--channel-rpm=600000 : Rate limit per minute of the dedicated tracking channel}
        {--output='.self::DEFAULT_OUTPUT.' : Where to write the pool JSON consumed by k6}
        {--force : Actually write (default is a dry run)}';

    protected $description = 'Seed the additive, idempotent load-test pool (LT prefix) and export the pool JSON for k6';

    public function handle(CommandBus $bus): int
    {
        if (app()->environment('production')) {
            $this->error('loadtest:prepare refuses to run in production.');

            return self::FAILURE;
        }

        $drivers = $this->intOption('drivers', 50, 1, 5000);
        $stops = $this->intOption('stops', 8, 1, 50);
        $operators = $this->intOption('operators', 50, 0, 2000);
        $logins = $this->intOption('logins', 10, 0, 500);
        $batch = $this->batchTag();

        if ($batch === null) {
            $this->error('The --batch tag must be exactly 6 characters (A-Z, 0-9).');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Load-test pool plan: %d drivers x %d stops (%d shipments), %d operators, %d login accounts, batch %s.',
            $drivers,
            $stops,
            $drivers * $stops,
            $operators,
            $logins,
            $batch,
        ));
        $this->line(sprintf(
            'Already seeded: %d pool users, %d pool vehicles, %d pool shipments.',
            User::query()->where('email', 'like', self::EMAIL_PREFIX.'%@'.self::EMAIL_DOMAIN)->count(),
            DB::table('fleet_vehicles')->where('plate', 'like', self::PLATE_PREFIX.'%')->count(),
            DB::table('shipments')->where('external_id', 'like', self::SHIPMENT_LIKE)->count(),
        ));

        if ($this->option('force') !== true) {
            $this->warn('Dry run: nothing was written. Re-run with --force to seed the pool.');

            return self::SUCCESS;
        }

        $node = $this->baseNode();
        if ($node === null) {
            $this->error('No base node found in network_nodes. Seed the network first or pass --node=<code>.');

            return self::FAILURE;
        }

        $password = $this->stringOption('password') !== '' ? $this->stringOption('password') : 'password123';
        // Un solo hash para el pool entero: el cast `hashed` del modelo respeta un valor ya hasheado, así
        // sembrar miles de cuentas no paga miles de bcrypt.
        $passwordHash = Hash::make($password);

        $seededDrivers = $this->seedDrivers($bus, $drivers, $stops, $batch, $node, $passwordHash);
        if ($seededDrivers === []) {
            $this->error('No driver could be seeded; the pool was not exported.');

            return self::FAILURE;
        }

        $pool = [
            'version' => 1,
            'prefix' => self::SHIPMENT_PREFIX,
            'batch' => $batch,
            'generated_at' => now()->toIso8601String(),
            'environment' => (string) app()->environment(),
            'password' => $password,
            'channel' => $this->seedChannel(),
            'drivers' => $seededDrivers,
            'operators' => $this->seedOperators($operators, $passwordHash),
            'login_users' => $this->seedLoginUsers($logins, $passwordHash),
        ];

        $path = $this->outputPath();
        File::ensureDirectoryExists(dirname($path));
        // k6 no crea carpetas al exportar el resumen: se deja lista la de resultados junto al pool.
        File::ensureDirectoryExists(dirname($path).DIRECTORY_SEPARATOR.'results');
        File::put($path, json_encode($pool, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->info(sprintf(
            'Pool ready: %d drivers, %d operators, %d login accounts. Exported to %s',
            count($pool['drivers']),
            count($pool['operators']),
            count($pool['login_users']),
            $path,
        ));
        if (count($seededDrivers) < $drivers) {
            $this->warn(sprintf('%d drivers failed and were left out of the pool.', $drivers - count($seededDrivers)));
        }
        $this->line('Tokens expire per SANCTUM_TOKEN_EXPIRATION; re-run this command to rotate them before a new session.');

        return self::SUCCESS;
    }

    /**
     * Siembra los choferes uno por uno. Un chofer que falla se informa y queda fuera del pool; varias
     * fallas seguidas abortan (error sistemático: falta una migración, un seeder base, etc.).
     *
     * @return list<array<string, mixed>>
     */
    private function seedDrivers(CommandBus $bus, int $drivers, int $stops, string $batch, stdClass $node, string $passwordHash): array
    {
        $nodeId = (string) $node->id;
        $lat = is_numeric($node->lat) ? (float) $node->lat : self::FALLBACK_LAT;
        $lng = is_numeric($node->lng) ? (float) $node->lng : self::FALLBACK_LNG;

        $bar = $this->output->createProgressBar($drivers);
        $bar->start();

        $pool = [];
        $consecutiveFailures = 0;
        for ($n = 1; $n <= $drivers; $n++) {
            try {
                $pool[] = $this->seedDriver($bus, $n, $stops, $batch, $nodeId, $lat, $lng, $passwordHash);
                $consecutiveFailures = 0;
            } catch (Throwable $exception) {
                $consecutiveFailures++;
                $this->newLine();
                $this->warn(sprintf('Driver %04d failed: %s', $n, $exception->getMessage()));

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $this->error('Too many consecutive failures; aborting the seeding.');

                    break;
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        return $pool;
    }

    /**
     * Un chofer completo: camión, conductor de flota, cuenta con rol chofer, dispositivo + token y la ruta
     * del día con sus paradas (envío recibido, seguimiento abierto y ligado, parada en la ruta y entrega
     * abierta lista para el POD).
     *
     * @return array<string, mixed>
     */
    private function seedDriver(
        CommandBus $bus,
        int $n,
        int $stops,
        string $batch,
        string $nodeId,
        float $lat,
        float $lng,
        string $passwordHash,
    ): array {
        $plate = sprintf('%s%04d', self::PLATE_PREFIX, $n);
        $vehicleId = $this->idOf('fleet_vehicles', 'plate', $plate);
        if ($vehicleId === null) {
            $bus->dispatch(new RegisterVehicle($plate, 3000, 18.0, $nodeId));
            $vehicleId = $this->idOf('fleet_vehicles', 'plate', $plate)
                ?? throw new RuntimeException("vehicle {$plate} was not registered");
        }

        $license = sprintf('%s%06d', self::LICENSE_PREFIX, $n);
        $driverId = $this->idOf('fleet_drivers', 'license_number', $license);
        if ($driverId === null) {
            $bus->dispatch(new RegisterDriver(
                sprintf('LT Driver %04d', $n),
                $license,
                now()->addYears(2)->format('Y-m-d'),
                [$vehicleId],
            ));
            $driverId = $this->idOf('fleet_drivers', 'license_number', $license)
                ?? throw new RuntimeException("driver {$license} was not registered");
        }

        $email = $this->email('driver', $n);
        $user = $this->ensureUser($email, sprintf('LT Driver %04d', $n), Role::Chofer, $passwordHash, $driverId);
        $fingerprint = sprintf('%sdriver-%04d', self::DEVICE_PREFIX, $n);

        $seededStops = [];
        for ($s = 1; $s <= $stops; $s++) {
            $seededStops[] = $this->seedStop($bus, $n, $s, $batch, $vehicleId, $lat, $lng);
        }

        return [
            'email' => $email,
            'token' => $this->mintToken($user, $fingerprint),
            'user_id' => (string) $user->getKey(),
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'plate' => $plate,
            'device_fingerprint' => $fingerprint,
            'stops' => $seededStops,
        ];
    }

    /**
     * Una parada: el envío entra por el ingreso único de pedidos (idempotente por fuente + external_id),
     * se abre y liga su seguimiento (lo mismo que hace el reactor del outbox, acá de forma síncrona para
     * no depender de que el worker esté corriendo), se suma a la ruta abierta del camión y se abre su
     * entrega, que queda lista para el POD.
     *
     * @return array{shipment_id: string, order_reference: string, unit_ids: list<string>, tracking_token: string}
     */
    private function seedStop(CommandBus $bus, int $n, int $s, string $batch, string $vehicleId, float $lat, float $lng): array
    {
        $reference = sprintf('%s%s-%04d-%03d', self::SHIPMENT_PREFIX, $batch, $n, $s);
        $customer = sprintf('%sCliente %04d-%03d', self::CUSTOMER_PREFIX, $n, $s);
        $address = sprintf('LT Load test stop %04d-%03d, Santa Cruz de la Sierra', $n, $s);

        $bus->dispatch(new ReceiveShipment(
            source: 'MANUAL',
            externalId: $reference,
            customerName: $customer,
            addressText: $address,
            addressReference: 'Load test delivery point',
            // Dispersión determinista de ±0,02° alrededor del nodo base (misma parada → misma coordenada).
            lat: round($lat + ((($n * 37 + $s * 11) % 200) - 100) / 5000, 7),
            lng: round($lng + ((($n * 53 + $s * 17) % 200) - 100) / 5000, 7),
            lines: [new ShipmentLineInput('LT-SKU-001', 'Producto de prueba de carga', 1)],
            orderReference: $reference,
        ));

        $shipmentId = $this->idOf('shipments', 'external_id', $reference)
            ?? throw new RuntimeException("shipment {$reference} was not received");

        $bus->dispatch(new OpenTracking($reference, $s - 1));
        $bus->dispatch(new BindShipmentToTracking($reference, $shipmentId, $s - 1));

        // La parada y la entrega se guardan por envío: si ya existen (aunque la ruta esté cerrada o la
        // entrega ya entregada por una corrida anterior) no se vuelven a despachar.
        if (!DB::table('execution_driver_route_stops')->where('shipment_id', $shipmentId)->exists()) {
            $bus->dispatch(new AttachShipmentToRoute($vehicleId, $shipmentId, $reference, $customer, $address));
        }
        if (!DB::table('execution_deliveries')->where('shipment_id', $shipmentId)->exists()) {
            $bus->dispatch(new OpenDelivery($shipmentId, $vehicleId));
        }

        $token = DB::table('tracking_shipments')->where('shipment_id', $shipmentId)->value('token');

        return [
            'shipment_id' => $shipmentId,
            'order_reference' => $reference,
            'unit_ids' => $this->stringList(
                DB::table('shipment_units')->where('shipment_id', $shipmentId)->orderBy('unit_index')->pluck('id')->all(),
            ),
            'tracking_token' => is_string($token) ? $token : '',
        ];
    }

    /**
     * Operadores (rol supervisor) con token ya emitido para los listados de oficina.
     *
     * @return list<array{email: string, token: string}>
     */
    private function seedOperators(int $operators, string $passwordHash): array
    {
        $pool = [];
        for ($n = 1; $n <= $operators; $n++) {
            $email = $this->email('ops', $n);
            $user = $this->ensureUser($email, sprintf('LT Operator %04d', $n), Role::Supervisor, $passwordHash);
            $pool[] = [
                'email' => $email,
                'token' => $this->mintToken($user, sprintf('%sops-%04d', self::DEVICE_PREFIX, $n)),
            ];
        }

        return $pool;
    }

    /**
     * Cuentas reservadas para el flujo de login de k6: NO se les emite token acá, porque el login rota el
     * token del dispositivo y desalojaría al que estuviera usando otro escenario.
     *
     * @return list<array{email: string, device_fingerprint: string}>
     */
    private function seedLoginUsers(int $logins, string $passwordHash): array
    {
        $pool = [];
        for ($n = 1; $n <= $logins; $n++) {
            $email = $this->email('login', $n);
            $this->ensureUser($email, sprintf('LT Login %04d', $n), Role::Chofer, $passwordHash);
            $pool[] = [
                'email' => $email,
                'device_fingerprint' => sprintf('%slogin-%04d', self::DEVICE_PREFIX, $n),
            ];
        }

        return $pool;
    }

    /**
     * Canal dedicado de la puerta channels para el rastreo público. Solo se guarda el hash del secreto,
     * así que en cada corrida se rota y se exporta el nuevo (idempotente: sigue siendo UN canal).
     *
     * @return array{key: string, secret: string}
     */
    private function seedChannel(): array
    {
        $channel = ApiChannel::query()->firstOrNew(['name' => self::CHANNEL_NAME]);
        $secret = ApiChannel::generateSecret();

        if (!$channel->exists) {
            $channel->key = ApiChannel::generateKey();
        }
        $channel->secret = $secret;
        $channel->rate_limit_per_minute = $this->intOption('channel-rpm', 600000, 60, 10000000);
        $channel->active = true;
        $channel->save();

        return ['key' => $channel->key, 'secret' => $secret];
    }

    /**
     * Cuenta del pool por email: se crea si falta, y siempre queda con la contraseña conocida, el rol y
     * (si aplica) el vínculo con su conductor de flota.
     */
    private function ensureUser(string $email, string $name, Role $role, string $passwordHash, ?string $driverId = null): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $passwordHash],
        );

        if (!$user->wasRecentlyCreated) {
            $user->password = $passwordHash;
        }
        if ($driverId !== null) {
            $user->driver_id = $driverId;
        }
        if ($user->isDirty()) {
            $user->save();
        }
        $user->assignRole($role);

        return $user;
    }

    /**
     * Emite el token por el MISMO camino que el login de la app: binding usuario↔dispositivo (con la regla
     * de una sesión por plataforma) y un solo token vigente por dispositivo.
     */
    private function mintToken(User $user, string $fingerprint): string
    {
        $device = $this->bindDevice($user, [
            'fingerprint' => $fingerprint,
            'platform' => 'android',
            'name' => 'LT k6 device',
        ]);
        $device->tokens()->delete();

        return $this->issueToken($user, $device);
    }

    /**
     * Nodo base de los camiones: el pedido por --node, o el CD de Santa Cruz, o el primer CD, o cualquiera.
     */
    private function baseNode(): ?stdClass
    {
        $code = trim($this->stringOption('node'));
        $columns = ['id', 'code', 'lat', 'lng'];

        if ($code !== '') {
            $node = DB::table('network_nodes')->where('code', $code)->first($columns);

            return $node instanceof stdClass ? $node : null;
        }

        $node = DB::table('network_nodes')->where('code', 'CD-SCZ-01')->first($columns)
            ?? DB::table('network_nodes')->where('code', 'like', 'CD-%')->orderBy('code')->first($columns)
            ?? DB::table('network_nodes')->orderBy('code')->first($columns);

        return $node instanceof stdClass ? $node : null;
    }

    private function email(string $kind, int $n): string
    {
        return sprintf('%s%s-%04d@%s', self::EMAIL_PREFIX, $kind, $n, self::EMAIL_DOMAIN);
    }

    /**
     * Etiqueta de lote de los envíos (6 caracteres): la pedida por --batch o la fecha de hoy (ymd). Un
     * lote nuevo agrega paradas frescas al pool sin tener que limpiar el anterior.
     */
    private function batchTag(): ?string
    {
        $batch = strtoupper(trim($this->stringOption('batch')));
        if ($batch === '') {
            return now()->format('ymd');
        }

        return preg_match('/^[A-Z0-9]{6}$/', $batch) === 1 ? $batch : null;
    }

    /**
     * Ruta absoluta del pool exportado: la de --output tal cual si ya es absoluta, o relativa a la raíz.
     */
    private function outputPath(): string
    {
        $output = trim($this->stringOption('output'));
        if ($output === '') {
            $output = self::DEFAULT_OUTPUT;
        }

        $isAbsolute = str_starts_with($output, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $output) === 1;

        return $isAbsolute ? $output : base_path($output);
    }

    private function idOf(string $table, string $column, string $value): ?string
    {
        $id = DB::table($table)->where($column, $value)->value('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  array<array-key, mixed> $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $list[] = (string) $value;
            }
        }

        return $list;
    }

    /**
     * Opción de consola como string (vacío si no vino): `option()` devuelve una unión ancha.
     */
    private function stringOption(string $key): string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : '';
    }

    /**
     * Opción numérica acotada a [min, max]; un valor ilegible cae al default.
     */
    private function intOption(string $key, int $default, int $min, int $max): int
    {
        $value = $this->stringOption($key);
        $number = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $number));
    }
}
