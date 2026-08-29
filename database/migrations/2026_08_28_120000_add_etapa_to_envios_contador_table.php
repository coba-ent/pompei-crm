<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa actual del envío, para que la pantalla pueda mostrar en qué anda un envío que todavía está
 * corriendo (generar los archivos de un período grande tarda minutos). Hasta ahora sólo existía
 * `estado` (pendiente/enviado/fallido), así que un envío en curso era indistinguible de uno colgado.
 *
 * Nullable a propósito: los envíos ya registrados no tienen etapa y no hay ninguna que inventarles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envios_contador', function (Blueprint $table) {
            $table->string('etapa')->nullable()->after('estado');
        });
    }

    public function down(): void
    {
        Schema::table('envios_contador', function (Blueprint $table) {
            $table->dropColumn('etapa');
        });
    }
};
