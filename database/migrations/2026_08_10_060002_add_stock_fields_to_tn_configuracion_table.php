<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marca de la última corrida de SincronizadorStock (spec 018, data-model.md §`tn_configuracion`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->timestamp('stock_ultima_sync_en')->nullable()->after('ultima_sync_resultado');
            $table->string('stock_ultima_sync_resultado', 255)->nullable()->after('stock_ultima_sync_en');
        });
    }

    public function down(): void
    {
        Schema::table('tn_configuracion', function (Blueprint $table) {
            $table->dropColumn(['stock_ultima_sync_en', 'stock_ultima_sync_resultado']);
        });
    }
};
