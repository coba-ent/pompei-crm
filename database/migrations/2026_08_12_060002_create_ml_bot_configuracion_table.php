<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila única con la configuración del bot de sugerencias (spec 033,
 * data-model.md § `ml_bot_configuracion`) — mismo patrón que `ml_configuracion`.
 * El flag de activo/inactivo vive en `funciones_avanzadas`, no acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_bot_configuracion', function (Blueprint $table) {
            $table->id();
            $table->text('instrucciones_tono')->nullable();
            $table->foreignId('actualizada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('actualizada_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_bot_configuracion');
    }
};
