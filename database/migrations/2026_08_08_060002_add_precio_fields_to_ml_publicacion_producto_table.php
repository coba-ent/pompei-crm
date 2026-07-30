<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estado de sincronización de precio por vínculo (spec 016, data-model.md §`ml_publicacion_producto`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->boolean('precio_pendiente')->default(false)->after('stock_error_en');
            $table->dateTime('precio_sincronizado_en')->nullable()->after('precio_pendiente');
            $table->string('precio_error', 255)->nullable()->after('precio_sincronizado_en');
            $table->dateTime('precio_error_en')->nullable()->after('precio_error');
        });
    }

    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn(['precio_pendiente', 'precio_sincronizado_en', 'precio_error', 'precio_error_en']);
        });
    }
};
