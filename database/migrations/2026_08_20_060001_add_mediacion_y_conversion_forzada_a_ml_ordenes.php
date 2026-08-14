<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * spec 066 (data-model.md): dos datos que hoy no existen y sostienen toda la feature.
 *
 * - `en_mediacion`: hoy la mediación vive sólo en el payload crudo y se pierde al
 *   sincronizar; por eso el cron convierte órdenes con un reclamo abierto.
 * - `forzada_*`: con qué motivo, quién y cuándo se forzó una conversión (FR-011),
 *   y contra qué compara el detector de la spec 063 para no repetir el aviso (FR-018).
 *
 * Sin backfill: las órdenes existentes quedan en `false` y se corrigen solas en la
 * sincronización siguiente, que reevalúa toda su ventana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            $table->boolean('en_mediacion')->default(false)->after('tiene_alerta_fraude');
            $table->string('forzada_motivo', 40)->nullable()->after('convertida_por');
            $table->foreignId('forzada_por_id')->nullable()->after('forzada_motivo')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('forzada_en')->nullable()->after('forzada_por_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_ordenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('forzada_por_id');
            $table->dropColumn(['en_mediacion', 'forzada_motivo', 'forzada_en']);
        });
    }
};
