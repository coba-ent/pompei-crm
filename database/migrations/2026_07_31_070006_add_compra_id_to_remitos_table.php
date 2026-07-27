<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remito también sobre una Compra (spec 009): agrega `compra_id` junto al
 * `venta_id` existente (ya nullable, ver 2026_07_30_060007) — exactamente uno
 * de los dos debe estar seteado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->foreignId('compra_id')->nullable()->after('venta_id')->constrained('compras')->cascadeOnDelete();
            $table->index('compra_id');
        });
    }

    public function down(): void
    {
        Schema::table('remitos', function (Blueprint $table) {
            $table->dropForeign(['compra_id']);
            $table->dropColumn('compra_id');
        });
    }
};
