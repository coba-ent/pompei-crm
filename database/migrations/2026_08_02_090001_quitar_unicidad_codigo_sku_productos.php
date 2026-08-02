<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El código/SKU de producto no es único en Contagram real (ninguna de las
 * dos integraciones vigentes, Mercado Libre y Tiendanube, depende de esa
 * unicidad: ambas vinculan por `Producto.id`, no por `codigo`/`sku`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique('productos_codigo_unique');
        });

        Schema::table('producto_variantes', function (Blueprint $table) {
            $table->dropUnique('producto_variantes_sku_unique');
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->unique('codigo');
        });

        Schema::table('producto_variantes', function (Blueprint $table) {
            $table->unique('sku');
        });
    }
};
