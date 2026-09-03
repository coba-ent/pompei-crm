<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 096: identifica qué LÍNEA del comprobante de origen ajusta cada NotaCreditoDebitoItem, no
 * sólo el producto — bug real (venta 24854: 3 líneas del mismo producto fundidas en una, total
 * propuesto a la mitad). Nullable y sin backfill: las NC/ND ya existentes no tienen forma de
 * reconstruir a qué línea correspondió cada ajuste (ver spec.md Assumptions).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->foreignId('venta_item_id')->nullable()->after('producto_id')
                ->constrained('venta_items')->nullOnDelete();
            $table->foreignId('compra_item_id')->nullable()->after('venta_item_id')
                ->constrained('compra_items')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nota_credito_debito_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('venta_item_id');
            $table->dropConstrainedForeignId('compra_item_id');
        });
    }
};
