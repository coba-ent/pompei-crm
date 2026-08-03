<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrador generado por IA para un mensaje entrante (spec 033, data-model.md
 * § `ml_sugerencias`). Sin `empresa_id` (single-tenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_sugerencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_mensaje_id')->constrained('ml_mensajes')->cascadeOnDelete();
            $table->text('texto_sugerido')->nullable();
            $table->string('estado', 20)->default('generando'); // generando | lista | error
            $table->string('error_mensaje')->nullable();
            $table->timestamp('generada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_sugerencias');
    }
};
