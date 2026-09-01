<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca los movimientos que vienen de la carga histórica de Contagram (spec 094).
 *
 * Es lo que permite deshacer una corrida borrando exactamente lo que insertó, sin depender de un
 * rango de fechas ni de ids consecutivos (FR-018). Los movimientos que genera el CRM normalmente la
 * dejan en NULL, así que la separación entre lo histórico y lo real es explícita y consultable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_stock', function (Blueprint $tabla) {
            $tabla->unsignedBigInteger('carga_historica_id')->nullable()->after('usuario_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_stock', function (Blueprint $tabla) {
            $tabla->dropIndex(['carga_historica_id']);
            $tabla->dropColumn('carga_historica_id');
        });
    }
};
