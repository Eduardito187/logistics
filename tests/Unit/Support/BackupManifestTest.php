<?php

declare(strict_types=1);

use App\Support\Database\BackupBundle;
use App\Support\Database\BackupManifest;
use App\Support\Database\PgEndpoint;
use App\Support\Database\PostgresBackup;

// Lógica PURA del backup (sin Laravel ni base): comparación de manifest, TOC, nombres auxiliares, versiones.

function bkDatabase(array $overrides = []): array
{
    return array_replace_recursive([
        'inventory' => [
            'schemas' => ['public'],
            'tables' => ['public.orders', 'public.orders_2026', 'public.users'],
            'partitioned_tables' => ['public.events'],
            'indexes' => ['public.users_pkey'],
            'constraints' => ['public.users.users_pkey'],
            'views' => ['public.v_users'],
            'materialized_views' => [],
            'functions' => ['public.touch()'],
            'triggers' => ['public.users.trg_touch'],
            'sequences' => ['public.users_id_seq' => 10, 'public.unused_seq' => null],
            'extensions' => ['plpgsql' => '1.0', 'postgis' => '3.5.7'],
        ],
        'row_counts' => ['public.orders' => 0, 'public.orders_2026' => 5, 'public.users' => 3],
    ], $overrides);
}

it('un inventario idéntico no tiene diferencias', function (): void {
    expect(BackupManifest::compare(bkDatabase(), bkDatabase()))->toBe([]);
});

it('detecta conteos de filas distintos, tablas faltantes y sobrantes', function (): void {
    $actual = bkDatabase(['row_counts' => ['public.users' => 2]]);
    unset($actual['row_counts']['public.orders']);
    $actual['row_counts']['public.extra'] = 1;

    $items = array_column(BackupManifest::compare(bkDatabase(), $actual), 'actual', 'item');

    expect($items)->toBe(['rows: public.orders' => 'missing', 'rows: public.users' => '2', 'rows: public.extra' => '1']);
});

it('detecta objetos de estructura faltantes o sobrantes (índices, funciones, triggers, vistas)', function (): void {
    $actual = bkDatabase();
    $actual['inventory']['indexes'] = [];
    $actual['inventory']['functions'][] = 'public.other()';
    $actual['inventory']['triggers'] = [];
    $actual['inventory']['views'] = [];

    $items = array_column(BackupManifest::compare(bkDatabase(), $actual), 'actual', 'item');

    expect($items)->toMatchArray([
        'indexes: public.users_pkey' => 'missing',
        'functions: public.other()' => 'present',
        'triggers: public.users.trg_touch' => 'missing',
        'views: public.v_users' => 'missing',
    ]);
});

it('exige que una secuencia restaurada no quede atrás del manifest (sí puede adelantarse)', function (): void {
    $behind = bkDatabase(['inventory' => ['sequences' => ['public.users_id_seq' => 9]]]);
    $ahead = bkDatabase(['inventory' => ['sequences' => ['public.users_id_seq' => 11]]]);

    expect(BackupManifest::compare(bkDatabase(), $behind))->toBe([
        ['item' => 'sequences: public.users_id_seq', 'expected' => '>= 10', 'actual' => '9'],
    ])->and(BackupManifest::compare(bkDatabase(), $ahead))->toBe([]);
});

it('compara extensiones por nombre (la versión puede variar entre servidores)', function (): void {
    $otherVersion = bkDatabase(['inventory' => ['extensions' => ['postgis' => '3.6.0']]]);
    $missing = bkDatabase();
    unset($missing['inventory']['extensions']['postgis']);

    expect(BackupManifest::compare(bkDatabase(), $otherVersion))->toBe([])
        ->and(BackupManifest::compare(bkDatabase(), $missing))->toBe([
            ['item' => 'extensions: postgis', 'expected' => 'present', 'actual' => 'missing'],
        ]);
});

it('extrae las tablas del TOC de pg_restore --list ignorando TABLE DATA y TABLE ATTACH', function (): void {
    $toc = <<<'TOC'
        ;
        ; Archive created at 2026-11-11 10:00:00 -04
        ;
        215; 1259 16390 TABLE public users logistics
        216; 1259 16400 TABLE public events logistics
        217; 1259 16410 TABLE public events_2026 logistics
        5001; 0 0 TABLE ATTACH public events_2026 logistics
        5100; 0 16390 TABLE DATA public users logistics
        300; 1259 16420 INDEX public users_pkey logistics
        TOC;

    expect(BackupManifest::tablesFromToc($toc))->toBe(['public.events', 'public.events_2026', 'public.users'])
        ->and(BackupManifest::compareToc(['public.users', 'public.gone'], ['public.users']))->toBe([
            ['item' => 'tables: public.gone', 'expected' => 'present (TOC)', 'actual' => 'missing'],
        ]);
});

it('arma nombres auxiliares dentro del límite de 63 bytes y solo reconoce scratch como descartables', function (): void {
    $at = new DateTimeImmutable('2026-11-11 10:05:09');
    $long = str_repeat('x', 80);

    expect(BackupManifest::auxiliaryName('logistics', 'verify', $at))->toBe('logistics_verify_20261111_100509')
        ->and(strlen(BackupManifest::auxiliaryName($long, 'restore', $at)))->toBe(63)
        ->and(BackupManifest::isScratchName('logistics_verify_20261111_100509'))->toBeTrue()
        ->and(BackupManifest::isScratchName('logistics_restore_20261111_100509'))->toBeTrue()
        // Guard: jamás la base viva ni la _prev_ (rollback).
        ->and(BackupManifest::isScratchName('logistics'))->toBeFalse()
        ->and(BackupManifest::isScratchName('logistics_prev_20261111_100509'))->toBeFalse()
        ->and(BackupManifest::quoteIdent('a"b'))->toBe('"a""b"');
});

it('resume cantidades de estructura y total de filas', function (): void {
    expect(BackupManifest::summary(bkDatabase()))->toMatchArray([
        'tables' => 3, 'partitioned_tables' => 1, 'sequences' => 2, 'extensions' => 2, 'row_total' => 8,
    ]);
});

it('lee el major de pg_dump --version', function (): void {
    expect(PostgresBackup::parseMajorVersion('pg_dump (PostgreSQL) 18.6'))->toBe(18)
        ->and(PostgresBackup::parseMajorVersion('pg_restore (PostgreSQL) 17.2 (Debian 17.2-1)'))->toBe(17)
        ->and(PostgresBackup::parseMajorVersion('command not found'))->toBeNull();
});

it('construye los comandos de tar del bundle solo con los dos miembros conocidos', function (): void {
    expect(BackupBundle::packCommand('/b/x.tar.tmp', '/w'))->toBe(['tar', '--force-local', '-cf', '/b/x.tar.tmp', '-C', '/w', 'manifest.json', 'database.dump'])
        ->and(BackupBundle::unpackCommand('/b/x.tar', '/w'))->toContain('manifest.json', 'database.dump', '--no-same-owner')
        ->and(BackupBundle::defaultPath('/backups/', new DateTimeImmutable('2026-11-11 10:05:09')))->toBe('/backups/logistics-20261111-100509.tar');
});

it('detecta un endpoint que parece PgBouncer y arma el DSN sin credenciales', function (): void {
    $bouncer = new PgEndpoint('pgbouncer', '6432', 'logistics', 'u', 'secret');
    $direct = $bouncer->withServer('10.0.0.5', '5432');

    expect($bouncer->looksLikePooler())->toBeTrue()
        ->and($direct->looksLikePooler())->toBeFalse()
        ->and($direct->direct)->toBeTrue()
        ->and($direct->dsn('postgres'))->toBe('pgsql:host=10.0.0.5;port=5432;dbname=postgres;connect_timeout=10')
        ->and($direct->dsn())->not->toContain('secret');
});
