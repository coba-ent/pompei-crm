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
            $table->unsignedInteger('stock_intentos_fallidos')->default(0)->after('stock_error_en');
            $table->dateTime('stock_error_desde')->nullable()->after('stock_intentos_fallidos');
            $table->boolean('stock_requiere_intervencion')->default(false)->after('stock_error_desde');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $table) {
            $table->dropColumn(['stock_intentos_fallidos', 'stock_error_desde', 'stock_requiere_intervencion']);
        });
    }
};
