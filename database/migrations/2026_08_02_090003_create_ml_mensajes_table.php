<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mensajes individuales de una conversación de Mensajería (spec 032,
 * data-model.md § `ml_mensajes`). `ml_id` es la clave natural de Mercado
 * Libre (question_id / message_id) usada para idempotencia del webhook (R4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_mensajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_conversacion_id')->constrained('ml_conversaciones')->cascadeOnDelete();
            $table->string('ml_id', 40)->unique();
            $table->string('origen', 20); // comprador | negocio
            $table->text('texto');
            $table->timestamp('enviado_en');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_mensajes');
    }
};
