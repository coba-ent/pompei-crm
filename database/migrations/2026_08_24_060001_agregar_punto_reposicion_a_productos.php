<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 073 — el punto de reposición deja de vivir en una lista de precios disfrazada
 * (donde lo dejó la importación de datos reales) y pasa a ser un atributo del producto.
 *
 * `null` o `0` = el producto no se controla y nunca genera alerta ni notificación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unsignedInteger('punto_reposicion')->nullable()->after('costo');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('punto_reposicion');
        });
    }
};
