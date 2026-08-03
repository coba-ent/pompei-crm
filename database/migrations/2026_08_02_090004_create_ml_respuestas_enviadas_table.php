<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría de respuestas enviadas a Mercado Libre (spec 032, data-model.md
 * § `ml_respuestas_enviadas`, FR-006). El índice único parcial sobre
 * `ml_mensaje_id` con `resultado=exito` es la garantía real de FR-007 (no
 * doble respuesta), no sólo la validación de aplicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_respuestas_enviadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ml_mensaje_id')->constrained('ml_mensajes')->cascadeOnDelete();
            $table->text('texto_enviado');
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('enviado_en');
            $table->string('resultado', 10); // exito | error
            $table->string('error_mensaje')->nullable();
            $table->timestamps();
        });

        // MySQL no soporta índices únicos parciales nativos: se emula con una
        // columna generada que sólo es no-nula cuando resultado=exito (FR-007).
        Schema::table('ml_respuestas_enviadas', function (Blueprint $table) {
            $table->unsignedBigInteger('ml_mensaje_id_si_exito')
                ->storedAs("CASE WHEN resultado = 'exito' THEN ml_mensaje_id ELSE NULL END")
                ->nullable()
                ->after('resultado');
            $table->unique('ml_mensaje_id_si_exito', 'ml_respuestas_enviadas_una_exitosa_por_mensaje');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_respuestas_enviadas');
    }
};
