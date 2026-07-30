<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lista de Precios que gestiona los precios de Mercado Libre (spec 016, data-model.md §`ml_configuracion`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->foreignId('lista_precio_id')->nullable()->after('categoria_venta_id')->constrained('listas_precio')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lista_precio_id');
        });
    }
};
