<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** "Vendedor por defecto" de MercadoLibre (spec 020, FR-010) — mismo patrón que `categoria_venta_id`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->foreignId('vendedor_id')->nullable()->after('categoria_venta_id')->constrained('vendedores')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendedor_id');
        });
    }
};
