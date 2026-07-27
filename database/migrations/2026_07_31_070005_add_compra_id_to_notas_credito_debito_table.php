<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NC/ND también sobre una Compra (spec 009): agrega `compra_id` junto al
 * `venta_id` existente (ya nullable, ver 2026_07_30_060006) — exactamente uno
 * de los dos debe estar seteado (constraint de aplicación, data-model.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->foreignId('compra_id')->nullable()->after('venta_id')->constrained('compras')->cascadeOnDelete();
            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::table('notas_credito_debito', function (Blueprint $table) {
            $table->dropForeign(['compra_id']);
            $table->dropColumn('compra_id');
        });
    }
};
