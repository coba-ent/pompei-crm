<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vinculación 1:1 variante de Tiendanube ↔ producto del CRM — spec 017,
 * data-model.md §`tn_variante_producto`. Los dos índices únicos son la
 * garantía estructural de FR-022, no sólo la validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tn_variante_producto', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('variant_id')->unique();
            $table->foreignId('producto_id')->unique()->constrained('productos')->cascadeOnDelete();
            $table->string('nombre_variante_tn', 255)->nullable();
            $table->foreignId('vinculada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tn_variante_producto');
    }
};
