<?php

declare(strict_types=1);

namespace Logistics\Labels\Presentation\Console;

use Illuminate\Console\Command;

/**
 * Genera un par de claves Ed25519 para firmar etiquetas.
 *
 * Imprime las líneas listas para .env y la clave pública que se distribuye a la app.
 * No escribe nada en disco: el secreto lo guarda quien corre el comando.
 */
final class GenerateSigningKeyCommand extends Command
{
    protected $signature = 'logistics:labels:keygen {--key-id=1 : Id de la nueva clave (campo "k" del QR)}';

    protected $description = 'Generate an Ed25519 key pair for signing package labels';

    public function handle(): int
    {
        $keyId = (int) $this->option('key-id');
        if ($keyId < 1) {
            $this->error('--key-id must be >= 1');

            return self::FAILURE;
        }

        $keyPair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($keyPair));
        $public = base64_encode(sodium_crypto_sign_publickey($keyPair));

        $this->line('# .env (server only, keep secret)');
        $this->line("LABELS_ACTIVE_KEY_ID={$keyId}");
        $this->line("LABELS_SIGNING_KEY={$secret}");
        $this->newLine();
        $this->line('# Public key for the mobile app (safe to distribute)');
        $this->line("{$keyId}:{$public}");

        return self::SUCCESS;
    }
}
