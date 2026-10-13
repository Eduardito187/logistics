<?php

declare(strict_types=1);

/*
| Regenera tests/Fixtures/label-test-vector.json.
|
| Solo hace falta si cambia el formato de firma (y en ese caso se sube `v`).
| Después de regenerarlo, copiar el archivo idéntico a logistics-app/test/fixtures/label_test_vector.json.
|
| Uso (dentro del container): php packages/logistics/labels/tests/Support/generate-test-vector.php
*/

use Logistics\Labels\Tests\Support\LabelFixtures;

require __DIR__.'/../../../../../vendor/autoload.php';

$signer = LabelFixtures::signer(1);
$label = $signer->sign(LabelFixtures::payload(1));

$data = json_decode($label->toQrContent(), true, 512, JSON_THROW_ON_ERROR);
$data['o'] = 'DM-260099999';
$tampered = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$vector = [
    'description' => 'Shared Ed25519 label test vector (logistics <-> logistics-app). Do not edit by hand.',
    'key_id' => 1,
    'public_key_base64' => base64_encode($signer->publicKey()),
    'canonical' => $label->payload->canonical(),
    'qr_content' => $label->toQrContent(),
    'tampered_qr_content' => $tampered,
];

file_put_contents(
    LabelFixtures::VECTOR_PATH,
    json_encode($vector, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
);

echo 'Written '.realpath(LabelFixtures::VECTOR_PATH).PHP_EOL;
