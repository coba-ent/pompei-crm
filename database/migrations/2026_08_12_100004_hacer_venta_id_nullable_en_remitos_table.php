<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `venta_id` está NOT NULL en producción aunque el código ya lo declara nullable (research.md
 * §R2) — el mismo bug que ya se corrigió en `notas_credito_debito.venta_id`. Sin esto, crear un
 * remito de Compra falla porque sólo se setea `compra_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->foreignId('venta_id')->nullable(false)->change();
        });
    }
};
