<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lista de Precios que gestiona los precios de las variantes vinculadas de
 * Tiendanube (spec 018 ampliación, data-model.md §`tn_configuracion` precio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->foreignId('lista_precio_id')->nullable()->after('stock_ultima_sync_resultado')
                ->constrained('listas_precio')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lista_precio_id');
        });
    }
};
