<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * legacy_id identifica una venta importada desde los Excel históricos de Contagram
 * (formato "{año del archivo}-{Id original del Excel}", ej. "2021-17") — el Id del
 * Excel no es único entre archivos de distintos años, se repite. Sirve solo para que
 * el importador sea idempotente (no duplicar si se corre dos veces); las ventas
 * nuevas creadas desde la app quedan con legacy_id null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('legacy_id')->nullable()->unique()->after('submit_token');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropUnique(['legacy_id']);
            $table->dropColumn('legacy_id');
        });
    }
};
