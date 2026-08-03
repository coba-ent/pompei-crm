<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amplía la auditoría de envío (spec 032) con la referencia a la sugerencia
 * de IA que originó la respuesta, si hubo alguna (spec 033, FR-010). No toca
 * el índice único `ml_mensaje_id_si_exito` que garantiza el guard de doble
 * respuesta (FR-007, spec 032) — sigue exactamente igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_respuestas_enviadas', function (Blueprint $table) {
            $table->foreignId('ml_sugerencia_id')->nullable()->after('ml_mensaje_id')
                ->constrained('ml_sugerencias')->nullOnDelete();
            $table->boolean('sugerencia_editada')->nullable()->after('ml_sugerencia_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_respuestas_enviadas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ml_sugerencia_id');
            $table->dropColumn('sugerencia_editada');
        });
    }
};
