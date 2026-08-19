<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 065: el stock propio de una publicación Full se escribe contra el "user product",
 * no contra el ítem — `PUT /items/{id}` rechaza `available_quantity` por ser un campo
 * derivado de las ubicaciones. Se persiste el id para no tener que resolverlo con un GET
 * extra por publicación en cada corrida de stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $tabla) {
            $tabla->string('user_product_id')->nullable()->after('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_publicacion_producto', function (Blueprint $tabla) {
            $tabla->dropColumn('user_product_id');
        });
    }
};
