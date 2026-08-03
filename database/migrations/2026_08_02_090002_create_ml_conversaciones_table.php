<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja de Mensajería de Mercado Libre (spec 032, data-model.md
 * § `ml_conversaciones`). Sin `empresa_id` (single-tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_conversaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20); // pregunta | post_venta
            $table->string('comprador_ml_id', 40);
            $table->string('comprador_nickname', 120)->nullable();
            $table->string('publicacion_id_ml', 40)->nullable();
            $table->foreignId('ml_publicacion_producto_id')->nullable()
                ->constrained('ml_publicacion_producto')->nullOnDelete();
            $table->foreignId('ml_orden_id')->nullable()
                ->constrained('ml_ordenes')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente'); // pendiente | respondida | cerrada
            $table->timestamp('ultimo_mensaje_en')->nullable();
            $table->timestamps();

            // Clave natural de agrupación (R4 de research.md): una conversación por
            // comprador+publicación (preguntas) o por orden (post-venta).
            $table->unique(['tipo', 'comprador_ml_id', 'publicacion_id_ml'], 'ml_conversaciones_pregunta_unica');
            $table->unique(['tipo', 'ml_orden_id'], 'ml_conversaciones_post_venta_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_conversaciones');
    }
};
