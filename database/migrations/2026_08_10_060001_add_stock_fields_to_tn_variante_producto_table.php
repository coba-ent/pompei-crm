<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de sincronización de stock por vínculo (spec 018, data-model.md
 * §`tn_variante_producto`). `tn_product_id` no existe todavía en esta tabla
 * (spec 017 sólo capturó `variant_id`) y es obligatorio para
 * `update_stock_and_price` (research.md R6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tn_variante_producto', function (Blueprint $table) {
            $table->string('tn_product_id', 50)->nullable()->after('variant_id');
            $table->boolean('stock_pendiente')->default(false)->after('vinculada_por');
            $table->timestamp('stock_sincronizado_en')->nullable()->after('stock_pendiente');
            $table->string('stock_error', 255)->nullable()->after('stock_sincronizado_en');
            $table->timestamp('stock_error_en')->nullable()->after('stock_error');
        });
    }

    public function down(): void
    {
        Schema::table('tn_variante_producto', function (Blueprint $table) {
            $table->dropColumn(['tn_product_id', 'stock_pendiente', 'stock_sincronizado_en', 'stock_error', 'stock_error_en']);
        });
    }
};
