<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marca de la última corrida de sincronización de stock (spec 013, data-model.md §`ml_configuracion`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dateTime('stock_ultima_sync_en')->nullable()->after('ultima_sync_resultado');
            $table->string('stock_ultima_sync_resultado', 255)->nullable()->after('stock_ultima_sync_en');
        });
    }

    public function down(): void
    {
        Schema::table('ml_configuracion', function (Blueprint $table) {
            $table->dropColumn(['stock_ultima_sync_en', 'stock_ultima_sync_resultado']);
        });
    }
};
