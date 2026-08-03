<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->index('producto_id', 'ml_publicacion_producto_producto_id_index');
            $table->dropUnique('ml_publicacion_producto_producto_id_unique');
        });

        Schema::table('tn_variante_producto', function (Blueprint $table) {
            $table->index('producto_id', 'tn_variante_producto_producto_id_index');
            $table->dropUnique('tn_variante_producto_producto_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->unique('producto_id', 'ml_publicacion_producto_producto_id_unique');
            $table->dropIndex('ml_publicacion_producto_producto_id_index');
        });

        Schema::table('tn_variante_producto', function (Blueprint $table) {
            $table->unique('producto_id', 'tn_variante_producto_producto_id_unique');
            $table->dropIndex('tn_variante_producto_producto_id_index');
        });
    }
};
