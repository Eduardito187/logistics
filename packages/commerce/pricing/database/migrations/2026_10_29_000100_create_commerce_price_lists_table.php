<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            // Moneda de la lista (ISO 4217), p. ej. BOB.
            $table->string('currency');
            // retail | b2b | c2c
            $table->string('channel')->index();
            // draft | active | archived
            $table->string('status')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_price_lists');
    }
};
