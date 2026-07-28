<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Líneas de orden de Mercado Libre — spec 012, data-model.md §3. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_orden_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_orden_id')->constrained('ml_ordenes')->cascadeOnDelete();
            $table->string('ml_item_id', 40);
            $table->string('ml_variation_id', 40)->nullable();
            $table->string('titulo', 255);
            $table->string('sku_vendedor', 120)->nullable();
            $table->decimal('cantidad', 14, 4);
            $table->decimal('precio_unitario', 14, 2);
            $table->decimal('precio_bruto', 14, 2)->nullable();
            $table->decimal('comision_ml', 14, 2)->nullable();
            $table->decimal('total_linea', 14, 2);
            $table->foreignId('producto_id')->nullable()->constrained('productos')->nullOnDelete();
            $table->timestamps();

            $table->index('ml_orden_id');
            $table->index('ml_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_orden_items');
    }
};
