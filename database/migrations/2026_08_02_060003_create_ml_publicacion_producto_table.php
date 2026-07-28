<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vinculación 1:1 publicación de Mercado Libre ↔ producto del CRM — spec 012,
 * data-model.md §4. Infraestructura compartida con la spec 013. Los dos
 * índices únicos son la garantía estructural de FR-022, no sólo la validación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_publicacion_producto', function (Blueprint $table) {
            $table->id();
            $table->string('ml_item_id', 40)->unique();
            $table->foreignId('producto_id')->unique()->constrained('productos')->cascadeOnDelete();
            $table->string('titulo_ml', 255)->nullable();
            $table->foreignId('vinculada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_publicacion_producto');
    }
};
