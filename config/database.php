<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        | C-3 — Conexión principal de la app. Puede apuntar directo a PostgreSQL o, en producción,
        | a PgBouncer en TRANSACTION POOLING para multiplexar miles de conexiones de PHP-FPM/workers
        | sobre un pool chico de conexiones reales a PG. Convención de infra: servicio `pgbouncer:6432`.
        | Para enrutar por PgBouncer basta con las envs (mismos user/pass/db):
        |
        |   DB_HOST=pgbouncer
        |   DB_PORT=6432
        |   DB_EMULATE_PREPARES=true   # imprescindible, ver abajo
        |
        | CAVEAT de PgBouncer (transaction pooling): NO soporta estado de sesión entre transacciones,
        | y en particular rompe los PREPARED STATEMENTS persistentes del lado del servidor (cada
        | transacción puede caer en un backend distinto, y el prepare de una no existe en la otra).
        | PDO/pgsql usa prepared statements con nombre del lado del servidor por defecto. La solución
        | es activar la EMULACIÓN de prepares en el cliente (`DB_EMULATE_PREPARES=true`): PDO interpola
        | los parámetros en PHP y manda SQL plano, sin dejar estado en el backend. Contra PG directo se
        | deja en `false` (prepares reales, más eficientes). Para DDL/migraciones, que sí necesitan
        | estado de sesión, usar la conexión directa `pgsql_direct` de abajo (`--database=pgsql_direct`).
        |
        | `search_path` se mantiene en 'public' (sin estado dependiente de sesión). PgBouncer con
        | `server_reset_query = DISCARD ALL` lo re-aplica limpio en cada checkout del pool.
        */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SEARCH_PATH', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // Compatibilidad con PgBouncer (transaction pooling): ver bloque de arriba. Emular prepares
            // evita dejar prepared statements con estado en el backend. Conexiones persistentes apagadas
            // por defecto (PgBouncer ya hace el pooling; persistir del lado de PHP lo contradice).
            'options' => [
                PDO::ATTR_EMULATE_PREPARES => (bool) env('DB_EMULATE_PREPARES', false),
                PDO::ATTR_PERSISTENT => (bool) env('DB_PERSISTENT', false),
            ],
        ],

        /*
        | C-3 — Conexión DIRECTA a PostgreSQL, sin pasar por PgBouncer. Para migraciones y cualquier
        | operación que necesite estado de sesión o prepared statements reales (DDL, `LOCK`, funciones
        | de sesión). Por defecto apunta al host/puerto reales de PG (`pgbouncer` NO debe usarse aquí);
        | si no se definen las envs directas, cae al mismo DB_HOST/DB_PORT. Uso: `artisan migrate
        | --database=pgsql_direct`.
        */
        'pgsql_direct' => [
            'driver' => 'pgsql',
            'url' => env('DB_DIRECT_URL', env('DB_URL')),
            'host' => env('DB_DIRECT_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_DIRECT_PORT', env('DB_PORT', '5432')),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => env('DB_SEARCH_PATH', 'public'),
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    /*
    | A-4 — Separación por DB de Redis/Valkey sobre una sola instancia. Cada flujo vive en su propia
    | base lógica para que la política de memoria no mezcle datos durables con datos evictable:
    |
    |   DB 0  `default`  colas, locks, job batches  → DURABLE, sin evicción (perder un job = perder trabajo)
    |   DB 1  `cache`    cache de la aplicación      → EVICTABLE con TTL, política `volatile-lru`
    |   DB 2  `session`  sesiones del panel/SPA      → DURABLE, sin evicción
    |
    | Solo la DB de cache lleva TTL y es candidata a evicción: alineado con `maxmemory-policy volatile-lru`
    | (evicta únicamente claves con expiración), de modo que un pico de cache jamás borra colas ni sesiones.
    | Mantener esta convención si se migra a instancias Redis separadas por tipo (A-4 avanzado).
    */
    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        // A-4: conexión DEDICADA para sesiones (DB 2), durable y sin evicción, separada de la cache
        // evictable (DB 1) y de las colas/locks (DB 0). La usa config/session.php (SESSION_CONNECTION).
        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '2'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
