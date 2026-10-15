<?php

declare(strict_types=1);

use Logistics\Shipments\Infrastructure\Ingestion\CsvShipmentSpreadsheetParser;
use Logistics\Shipments\Infrastructure\Ingestion\DelegatingShipmentSpreadsheetParser;

function delegatingParser(): DelegatingShipmentSpreadsheetParser
{
    return new DelegatingShipmentSpreadsheetParser(new CsvShipmentSpreadsheetParser);
}

it('delegates a well-formed CSV to the CSV parser and parses the order', function (): void {
    $csv = "source,external_id,customer_name,address_text,sku,product_name,quantity\n"
        .'STORE,ORD-1,Ana Perez,Av. Ballivian 123,SKU-1,Horno,2';

    $rows = delegatingParser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->isValid())->toBeTrue()
        ->and($rows[0]->command?->externalId)->toBe('ORD-1')
        ->and($rows[0]->command?->lines)->toHaveCount(1);
});

it('reports a CSV with a missing required column as an error row', function (): void {
    $csv = "source,external_id,customer_name,address_text,sku,product_name\n"
        .'STORE,ORD-1,Ana,Av. 1,SKU-1,Horno';

    $rows = delegatingParser()->parse($csv);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->isValid())->toBeFalse()
        ->and($rows[0]->error)->toContain('quantity');
});

it('rejects an XLSX (ZIP) file with an actionable message pointing to CSV', function (): void {
    $xlsx = "PK\x03\x04".str_repeat("\x00", 64); // firma ZIP de un .xlsx

    $rows = delegatingParser()->parse($xlsx);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->isValid())->toBeFalse()
        ->and($rows[0]->error)->toContain('XLSX')
        ->and($rows[0]->error)->toContain('CSV')
        ->and($rows[0]->error)->toContain('phpoffice/phpspreadsheet');
});

it('rejects a legacy XLS (OLE2) file with an actionable message', function (): void {
    $xls = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\x00", 32);

    $rows = delegatingParser()->parse($xls);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->isValid())->toBeFalse()
        ->and($rows[0]->error)->toContain('XLS');
});
