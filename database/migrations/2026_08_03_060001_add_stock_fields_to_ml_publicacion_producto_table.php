<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estado de sincronización de stock por vínculo (spec 013, data-model.md §`ml_publicacion_producto`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->boolean('stock_pendiente')->default(false)->after('vinculada_por');
            $table->dateTime('stock_sincronizado_en')->nullable()->after('stock_pendiente');
            $table->string('stock_error', 255)->nullable()->after('stock_sincronizado_en');
            $table->dateTime('stock_error_en')->nullable()->after('stock_error');
        });
    }

    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn(['stock_pendiente', 'stock_sincronizado_en', 'stock_error', 'stock_error_en']);
        });
    }
};
