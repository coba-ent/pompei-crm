<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spec 093 (US2): conservar el archivo subido asociado a su corrida.
 *
 * Los tres estados del archivo se DERIVAN de estas columnas, no se guarda un enum — un enum
 * aparte se desincronizaría con el archivo real el día que alguien lo borre a mano:
 *
 *   ruta = null y vencido_en = null  → nunca se guardó (corrida vieja, o el guardado falló)
 *   ruta ≠ null y vencido_en = null  → disponible
 *              vencido_en ≠ null     → venció por antigüedad
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacion_corridas', function (Blueprint $table) {
            // Ruta relativa dentro del disco `local`. Es única por corrida: `archivo_original`
            // NO sirve de clave, se repite entre corridas (FR-017).
            $table->string('archivo_guardado_ruta')->nullable()->after('archivo_original');
            $table->timestamp('archivo_guardado_en')->nullable()->after('archivo_guardado_ruta');
            $table->timestamp('archivo_vencido_en')->nullable()->after('archivo_guardado_en');
        });
    }

    public function down(): void
    {
        Schema::table('importacion_corridas', function (Blueprint $table) {
            $table->dropColumn(['archivo_guardado_ruta', 'archivo_guardado_en', 'archivo_vencido_en']);
        });
    }
};
