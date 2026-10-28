<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de cada cambio de tipo (spec 116): `crm` = confirmado desde Vinculaciones ML;
 * `mercadolibre` = cambio hecho en Mercado Libre y detectado por el CRM. Las filas existentes
 * son todas del CRM, así que el default las deja bien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ml_cambios_tipo_publicacion', function (Blueprint $table) {
            $table->string('origen', 20)->default('crm')->after('resultado')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ml_cambios_tipo_publicacion', function (Blueprint $table) {
            $table->dropIndex(['origen']);
            $table->dropColumn('origen');
        });
    }
};
