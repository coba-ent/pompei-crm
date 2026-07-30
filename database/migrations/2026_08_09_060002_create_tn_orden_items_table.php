<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Líneas de orden de Tiendanube — spec 017, data-model.md §`tn_orden_items`. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tn_orden_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tn_orden_id')->constrained('tn_ordenes')->cascadeOnDelete();
            $table->unsignedBigInteger('tn_product_id');
            $table->unsignedBigInteger('variant_id');
            $table->string('nombre_producto', 255);
            $table->string('nombre_variante', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->decimal('cantidad', 14, 4);
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('total_linea', 14, 2);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->timestamps();

            $table->index('tn_orden_id');
            $table->index('variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tn_orden_items');
    }
};
