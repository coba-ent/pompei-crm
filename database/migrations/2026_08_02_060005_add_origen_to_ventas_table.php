<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Origen de la Venta (FR-035, FR-035a) — data-model.md §6. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('origen', ['manual', 'presupuesto', 'mercadolibre'])->default('manual')->after('presupuesto_id');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
