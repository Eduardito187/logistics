<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Discos de evidencia S3-compatible (producción)
        |----------------------------------------------------------------------
        |
        | Un disco por tipo de evidencia operativa, cada uno sobre S3 (o un
        | compatible: MinIO/R2/Spaces vía AWS_ENDPOINT). Cada disco puede vivir
        | en su propio bucket (AWS_<TIPO>_BUCKET); si no se define, cae al bucket
        | general AWS_BUCKET usando `root` como prefijo de carpeta, para que la
        | plataforma funcione con un solo bucket en entornos chicos.
        |
        | Ciclo de vida (se aplica como lifecycle policy en el bucket, no en la
        | app; documentado en docs/deploy.md):
        |   - pod/checklist : evidencia legal de la entrega → retención larga
        |     (p.ej. 2 años), sin borrado automático; mover a almacenamiento frío
        |     (IA/Glacier) tras ~90 días.
        |   - damage        : evidencia de reclamos/siniestros → retención larga
        |     (p.ej. 5 años) por eventuales disputas; frío tras ~90 días.
        |   - collages      : imagen pública efímera que acompaña la notificación
        |     al cliente → expiración corta (p.ej. 30 días) vía lifecycle.
        |   - documents     : documentos de flota (SOAT, inspección) → viven
        |     mientras el documento esté vigente; limpieza manual.
        |
        | Todas las evidencias son privadas salvo `collages`, cuya URL pública se
        | manda al cliente en la notificación (ADR 006).
        */

        // Prueba de entrega: firma y fotos que registra el repartidor al entregar.
        'pod' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_POD_BUCKET', env('AWS_BUCKET')),
            'root' => env('AWS_POD_BUCKET') ? '' : 'pod',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Checklist de carga / salida de vehículo.
        'checklist' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_CHECKLIST_BUCKET', env('AWS_BUCKET')),
            'root' => env('AWS_CHECKLIST_BUCKET') ? '' : 'checklists',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Evidencia de daños (reclamos / siniestros).
        'damage' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_DAMAGE_BUCKET', env('AWS_BUCKET')),
            'root' => env('AWS_DAMAGE_BUCKET') ? '' : 'damages',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

        // Collage PNG público que acompaña la notificación al cliente (ADR 006).
        'collages' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_COLLAGE_BUCKET', env('AWS_BUCKET')),
            'root' => env('AWS_COLLAGE_BUCKET') ? '' : 'collages',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Documentos de flota (SOAT, inspección técnica, etc.).
        'documents' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_DOCUMENTS_BUCKET', env('AWS_BUCKET')),
            'root' => env('AWS_DOCUMENTS_BUCKET') ? '' : 'fleet-documents',
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'visibility' => 'private',
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
