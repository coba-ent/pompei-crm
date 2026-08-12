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
            // spec 063/FR-018: última cantidad efectivamente confirmada en Mercado Libre, para
            // poder mostrar la diferencia contra el stock actual del CRM sin tener que consultar
            // la API en cada carga del panel (no hay endpoint de "stock actual publicado").
            $table->unsignedInteger('ultimo_stock_publicado')->nullable()->after('stock_requiere_intervencion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn('ultimo_stock_publicado');
        });
    }
};
