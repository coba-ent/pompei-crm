<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaña de cuotas elegida en cada intento de cambio de tipo (spec 115, ajuste 16/10/2026).
 * `no-campaign` = la que ML aplica sin tag; null = intento sin elección de cuotas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_cambios_tipo_publicacion', function (Blueprint $table) {
            $table->string('cuotas', 40)->nullable()->after('lista_precio_id');
        });
    }

    public function down(): void
    {
        Schema::table('ml_cambios_tipo_publicacion', function (Blueprint $table) {
            $table->dropColumn('cuotas');
        });
    }
};
